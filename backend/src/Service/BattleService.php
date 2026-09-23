<?php

namespace App\Service;

use App\Battle\AbilityChoice;
use App\Battle\AbilityResolver;
use App\Battle\BitThrow;
use App\Battle\CombatResolver;
use App\Battle\Exchange;
use App\Battle\ExchangeMoveResult;
use App\Battle\ExchangeResolver;
use App\Battle\ExchangeRoundState;
use App\Battle\InteractiveExchangeEngine;
use App\Battle\RoundResult;
use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\Event;
use App\Enum\BattleMode;
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Exception\InvalidBattleStateException;
use App\Exception\NoPendingThrowException;
use App\Exception\NotBattleParticipantException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application service orchestrating battles: PvE/event fights against a bot
 * opponent, and PvP duels between two real characters. throwRound() always
 * comes first, so the player sees the revealed bits before deciding
 * anything. What follows depends on the surface:
 *
 *  - Interactive PvE/event (the Activity's Arena): submitExchangeMove(), one
 *    call per lead/respond decision — see docs/COMBAT_V2_DESIGN.md §7-8 and
 *    InteractiveExchangeEngine's class docblock.
 *  - Non-interactive PvE/event auto-play (bot text commands, tournament
 *    simulation): resolveRound() plays the whole round out in one call via
 *    ExchangeResolver, with the player's side driven by a single upfront
 *    AbilityChoice + heuristic instead of real per-exchange decisions.
 *  - PvP: submitActions() — still the older simultaneous-reveal
 *    CombatResolver (§7 of that doc explains why the interactive engine
 *    hasn't been ported to the live two-player protocol yet).
 *
 * Combat abilities (docs/BATTLE_RULES.md §3.1): whichever side has rolled
 * "action" faces picks an ability to spend them on — Flip (the original/
 * default), UnblockableDamage, Reroll, or DamageMirror — not a mix. In the
 * interactive flow the ability is chosen per action-bit move as it's
 * played; in the non-interactive engine it's one upfront choice for the
 * whole round. The bot opponent always uses Flip.
 *
 * PvP lifecycle (see docs/BATTLE_ROOM_DESIGN.md): createPvpChallenge()
 * creates the Battle itself in `waiting` status — that row *is* the invite.
 * acceptPvpChallenge() / markReady() move it toward `in_progress`, at which
 * point the first round is thrown automatically.
 */
class BattleService
{
    private const MONSTER_NAME = 'Тренировочный голем';
    private const MONSTER_HP = 20;
    // Each entry: [faceA, faceB, advantageA, advantageB]. Placeholder balance
    // (see docs/ROADMAP.md, Game Design backlog) — the monster deliberately
    // rolls advantage less often than a well-built player character, so it
    // rarely leads the exchange sequence (docs/COMBAT_V2_DESIGN.md §2).
    private const MONSTER_BITS = [
        [BitFace::Attack, BitFace::Defense, false, false],
        [BitFace::Attack, BitFace::Action, false, true],
        [BitFace::Defense, BitFace::Defense, false, false],
    ];
    public const XP_REWARD = 10;
    public const COIN_REWARD = 5;
    public const EVENT_XP_REWARD = 20;
    public const EVENT_COIN_REWARD = 15;
    // Deliberately not routed through QuestService::recordBattleWin() — PvP
    // wins don't count toward the weekly quest, same as simulated tournament
    // matches, so two of a player's own accounts can't farm it by duelling.
    public const PVP_XP_REWARD = 15;
    public const PVP_COIN_REWARD = 10;
    private const PVP_ROUND_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CombatResolver $combatResolver,
        private readonly ExchangeResolver $exchangeResolver,
        private readonly InteractiveExchangeEngine $interactiveEngine,
        private readonly AbilityResolver $abilityResolver,
        private readonly QuestService $questService,
    ) {
    }

    public function startPveBattle(Character $character): Battle
    {
        if ($character->getEnergy() < 1) {
            throw new InsufficientEnergyException('Not enough energy to start a battle.');
        }

        $character->setEnergy($character->getEnergy() - 1);

        $battle = new Battle($character, self::MONSTER_NAME, self::MONSTER_HP);
        $this->entityManager->persist($battle);
        $this->entityManager->flush();

        return $battle;
    }

    /**
     * Event battles don't cost energy and pay out bigger rewards — fighting
     * the event's monster is meant to be something anyone online can jump
     * into without it competing with their daily PvE energy budget.
     */
    public function startEventBattle(Character $character, Event $event): Battle
    {
        $battle = new Battle($character, $event->getMonsterName(), $event->getMonsterHp(), BattleMode::Event);
        $battle->setEvent($event);
        $this->entityManager->persist($battle);
        $this->entityManager->flush();

        return $battle;
    }

    // ---------------------------------------------------------------
    // PvP lifecycle
    // ---------------------------------------------------------------

    /**
     * The Battle row created here, in `waiting` status, *is* the duel
     * invite — no separate entity. Doesn't cost energy: PvP is a distinct
     * resource from the PvE energy budget (see docs/BATTLE_ROOM_DESIGN.md §8).
     */
    public function createPvpChallenge(Character $challenger, Character $opponent): Battle
    {
        $battle = Battle::createPvp($challenger, $opponent);
        $this->entityManager->persist($battle);
        $this->entityManager->flush();

        return $battle;
    }

    public function acceptPvpChallenge(Battle $battle): void
    {
        if (BattleStatus::Waiting !== $battle->getStatus()) {
            throw new InvalidBattleStateException('This challenge is no longer pending.');
        }

        $battle->acceptAsOpponent();
        $this->entityManager->flush();
    }

    public function declinePvpChallenge(Battle $battle): void
    {
        if (BattleStatus::Waiting !== $battle->getStatus()) {
            throw new InvalidBattleStateException('This challenge is no longer pending.');
        }

        $battle->setStatus(BattleStatus::Abandoned);
        $this->entityManager->flush();
    }

    /**
     * Marks the calling side ready. Once both sides are ready (and the
     * challenge was accepted), the battle flips to in_progress and the
     * first round is thrown immediately — neither player has to take an
     * explicit "start" action.
     */
    public function markReady(Battle $battle, Character $viewer): void
    {
        if (BattleStatus::Waiting !== $battle->getStatus()) {
            throw new InvalidBattleStateException('This duel is not waiting for players.');
        }

        $bothReady = $battle->markReady($this->requireSide($battle, $viewer));
        $this->entityManager->flush();

        if ($bothReady) {
            $battle->setStatus(BattleStatus::InProgress);
            $this->entityManager->flush();
            $this->throwRound($battle);
        }
    }

    /**
     * @return BattleRound|null null while waiting on the other side to submit;
     *                          the resolved round once both sides are in
     */
    public function submitActions(Battle $battle, Character $viewer, AbilityChoice $choice): ?BattleRound
    {
        if (!$battle->isPvp()) {
            throw new InvalidBattleStateException('submitActions() is PvP-only — use resolveRound() for PvE/event battles.');
        }
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }
        if (!$battle->hasPendingThrow()) {
            throw new NoPendingThrowException('No pending throw to resolve — call throwRound() first.');
        }

        $asOpponentSide = $this->requireSide($battle, $viewer);
        $rolledActionCount = $this->abilityResolver->countActionFaces(array_map(
            BitThrow::fromArray(...),
            $asOpponentSide ? $battle->getPendingOpponentThrows() : $battle->getPendingPlayerThrows(),
        ));
        $this->abilityResolver->assertAffordable($choice, $rolledActionCount);

        $battle->submitAbilityChoice($asOpponentSide, $choice->toArray());
        $this->applyRoundTimeoutIfExpired($battle);

        if (!$battle->bothAbilityChoicesSubmitted()) {
            $this->entityManager->flush();

            return null;
        }

        $characterChoice = AbilityChoice::fromArray($battle->getPendingCharacterAbilityChoice());
        $opponentChoice = AbilityChoice::fromArray($battle->getPendingOpponentAbilityChoice());
        $battle->clearPendingAbilityChoices();

        return $this->computeAndApplyRound($battle, $characterChoice, $opponentChoice);
    }

    /**
     * If the round's decision deadline has passed, any side that still
     * hasn't submitted is treated as having used no action targets — a
     * lazy check (no worker/cron), consistent with the project's
     * polling-only sync model. See docs/BATTLE_ROOM_DESIGN.md §6.
     */
    private function applyRoundTimeoutIfExpired(Battle $battle): void
    {
        if (!$battle->isRoundDeadlinePassed()) {
            return;
        }

        if (null === $battle->getPendingCharacterAbilityChoice()) {
            $battle->submitAbilityChoice(false, AbilityChoice::flip()->toArray());
        }
        if (null === $battle->getPendingOpponentAbilityChoice()) {
            $battle->submitAbilityChoice(true, AbilityChoice::flip()->toArray());
        }
    }

    private function requireSide(Battle $battle, Character $viewer): bool
    {
        if ($battle->getCharacter() === $viewer) {
            return false;
        }
        if ($battle->getOpponentCharacter() === $viewer) {
            return true;
        }

        throw new NotBattleParticipantException('This character is not part of this battle.');
    }

    // ---------------------------------------------------------------
    // Round loop (shared by PvE, event and PvP)
    // ---------------------------------------------------------------

    public function throwRound(Battle $battle): ThrowResult
    {
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }

        if ($battle->hasPendingThrow()) {
            // Idempotent: relevant for PvP, where either client might be the
            // one to trigger the throw — don't re-roll a round already in flight.
            return $this->currentThrowResult($battle);
        }

        $playerThrows = array_map(
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB()),
            $battle->getCharacter()->getAllBits(),
        );
        $opponentThrows = $battle->isPvp()
            ? array_map(
                static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB()),
                $battle->getOpponentCharacter()->getAllBits(),
            )
            : array_map(
                static fn (array $bit) => BitThrow::random($bit[0], $bit[1], $bit[2], $bit[3]),
                self::MONSTER_BITS,
            );

        $battle->setPendingThrows(
            array_map(static fn (BitThrow $t) => $t->toArray(), $playerThrows),
            array_map(static fn (BitThrow $t) => $t->toArray(), $opponentThrows),
        );
        $battle->setRoundDeadlineAt($battle->isPvp() ? new \DateTimeImmutable(sprintf('+%d seconds', self::PVP_ROUND_TIMEOUT_SECONDS)) : null);

        if (!$battle->isPvp()) {
            // Interactive step-by-step flow (docs/COMBAT_V2_DESIGN.md §7-8) —
            // PvP keeps the older whole-round-at-once submitActions() below.
            // startRound() may already auto-play the bot's opening move (or,
            // in the near-impossible case of a 0-bit character, resolve the
            // entire round instantly) — apply any such exchanges now.
            ['state' => $roundState, 'exchanges' => $exchanges] = $this->interactiveEngine->startRound($playerThrows, $opponentThrows);
            foreach ($exchanges as $exchange) {
                $this->applyExchangeDamage($battle, $exchange);
            }
            $battle->setPendingExchangeState($roundState->toArray());
        }

        $this->entityManager->flush();

        return $this->throwResultFromThrows($battle, $playerThrows, $opponentThrows);
    }

    private function currentThrowResult(Battle $battle): ThrowResult
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());

        return $this->throwResultFromThrows($battle, $playerThrows, $opponentThrows);
    }

    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    private function throwResultFromThrows(Battle $battle, array $playerThrows, array $opponentThrows): ThrowResult
    {
        $playerActionCount = $this->abilityResolver->countActionFaces($playerThrows);

        $turn = null;
        $incomingMove = null;
        $playerUsed = null;
        $opponentUsed = null;
        $stateArray = $battle->getPendingExchangeState();
        if (!$battle->isPvp() && null !== $stateArray) {
            $state = ExchangeRoundState::fromArray($stateArray);
            $turn = $this->interactiveEngine->currentTurn($state);
            $incomingMove = 'respond' === $turn ? $this->interactiveEngine->getIncomingMove($state) : null;
            $playerUsed = $state->playerUsed;
            $opponentUsed = $state->opponentUsed;
        }

        return new ThrowResult($playerThrows, $opponentThrows, $playerActionCount, $turn, $incomingMove, $playerUsed, $opponentUsed);
    }

    /**
     * PvE/event only — PvP rounds go through submitActions() instead, since
     * both real sides must submit before damage can be computed.
     */
    public function resolveRound(Battle $battle, AbilityChoice $characterChoice): BattleRound
    {
        if ($battle->isPvp()) {
            throw new InvalidBattleStateException('resolveRound() is PvE/event-only — use submitActions() for PvP.');
        }
        if (!$battle->hasPendingThrow()) {
            throw new NoPendingThrowException('No pending throw to resolve — call throwRound() first.');
        }

        $rolledActionCount = $this->abilityResolver->countActionFaces(array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows()));
        $this->abilityResolver->assertAffordable($characterChoice, $rolledActionCount);

        // PvE/event opponent (the bot) always uses Flip via BotActionStrategy —
        // represented here as a Flip choice with no explicit targets so
        // computeAndApplyRound's post-damage step is a no-op for its side.
        return $this->computeAndApplyRound($battle, $characterChoice, AbilityChoice::flip());
    }

    /**
     * PvE/event only — one step of the interactive exchange flow
     * (docs/COMBAT_V2_DESIGN.md §7-8): either leading (your turn to commit
     * bits) or responding to the bot's already-committed lead move,
     * inferred from the battle's own pending state rather than trusted
     * from the client. See InteractiveExchangeEngine's class docblock for
     * the underlying call pattern this wraps.
     *
     * @param int[] $indices
     */
    public function submitExchangeMove(Battle $battle, array $indices, ?AbilityChoice $ability): ExchangeMoveResult
    {
        if ($battle->isPvp()) {
            throw new InvalidBattleStateException('submitExchangeMove() is PvE/event-only.');
        }
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }

        $stateArray = $battle->getPendingExchangeState();
        if (null === $stateArray) {
            throw new NoPendingThrowException('No exchange in progress — call throwRound() first.');
        }
        $state = ExchangeRoundState::fromArray($stateArray);

        $turn = $this->interactiveEngine->currentTurn($state);
        if ('over' === $turn) {
            throw new InvalidBattleStateException('This round has already been fully played out.');
        }

        $botChoice = AbilityChoice::flip();
        $newExchanges = [];

        ['state' => $state, 'exchange' => $exchange] = 'lead' === $turn
            ? $this->interactiveEngine->submitLead($state, $indices, $ability, $botChoice)
            : $this->interactiveEngine->submitRespond($state, $indices, $ability);
        $newExchanges[] = $exchange;
        $this->applyExchangeDamage($battle, $exchange);
        $knockedOut = $this->isBattleKnockedOut($battle);

        // Keep auto-playing any further bot-driven exchanges (e.g. the
        // player is out of bits and the bot keeps swinging solo — see
        // docs/COMBAT_V2_DESIGN.md §3) until it's genuinely the player's
        // turn again, the round ends naturally, or someone is knocked out
        // mid-round (which cuts the round short right there).
        while (!$knockedOut) {
            ['state' => $state, 'exchange' => $exchange] = $this->interactiveEngine->autoAdvance($state, $botChoice);
            if (null === $exchange) {
                break;
            }
            $newExchanges[] = $exchange;
            $this->applyExchangeDamage($battle, $exchange);
            $knockedOut = $this->isBattleKnockedOut($battle);
        }

        // Faces can change mid-round (Flip) and are captured here regardless
        // of which branch below runs — the client needs the current values,
        // not just the ones from the original throw.
        $playerFaces = array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows);
        $opponentFaces = array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows);

        if ($knockedOut || $state->isOver()) {
            $round = $this->finalizeInteractiveRound($battle, $state);

            return new ExchangeMoveResult(
                roundComplete: true,
                newExchanges: $newExchanges,
                playerFaces: $playerFaces,
                opponentFaces: $opponentFaces,
                playerUsed: $state->playerUsed,
                opponentUsed: $state->opponentUsed,
                round: $round,
            );
        }

        $battle->setPendingExchangeState($state->toArray());
        $this->entityManager->flush();

        $turnNow = $this->interactiveEngine->currentTurn($state);

        return new ExchangeMoveResult(
            roundComplete: false,
            newExchanges: $newExchanges,
            playerFaces: $playerFaces,
            opponentFaces: $opponentFaces,
            playerUsed: $state->playerUsed,
            opponentUsed: $state->opponentUsed,
            turn: $turnNow,
            incomingMove: 'respond' === $turnNow ? $this->interactiveEngine->getIncomingMove($state) : null,
        );
    }

    private function applyExchangeDamage(Battle $battle, array $exchange): void
    {
        $character = $battle->getCharacter();
        $character->setHp($character->getHp() - $exchange['damageToPlayer']);
        $battle->setOpponentHp($battle->getOpponentHp() - $exchange['damageToOpponent']);
    }

    private function isBattleKnockedOut(Battle $battle): bool
    {
        return $battle->getCharacter()->getHp() <= 0 || $battle->getOpponentHp() <= 0;
    }

    private function finalizeInteractiveRound(Battle $battle, ExchangeRoundState $state): BattleRound
    {
        $battle->setPendingThrows(null, null);
        $battle->setPendingExchangeState(null);
        $battle->incrementRoundNumber();

        $round = new BattleRound(
            $battle,
            $battle->getRoundNumber(),
            array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows),
            array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows),
            array_sum(array_column($state->exchanges, 'damageToOpponent')),
            array_sum(array_column($state->exchanges, 'damageToPlayer')),
            $state->exchanges,
        );
        $this->entityManager->persist($round);

        $this->resolveOutcome($battle);
        $this->entityManager->flush();

        return $round;
    }

    private function computeAndApplyRound(Battle $battle, AbilityChoice $characterChoice, AbilityChoice $opponentChoice): BattleRound
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());
        $battle->setPendingThrows(null, null);
        $battle->setRoundDeadlineAt(null);

        // PvP still uses the older simultaneous-reveal CombatResolver — the
        // new priority/exchange engine (docs/COMBAT_V2_DESIGN.md) isn't wired
        // into the live two-player protocol yet (see §7 of that doc).
        $result = $battle->isPvp()
            ? $this->resolveRoundLegacy($playerThrows, $opponentThrows, $characterChoice, $opponentChoice)
            : $this->exchangeResolver->resolveRound($playerThrows, $opponentThrows, $characterChoice, $opponentChoice);

        $character = $battle->getCharacter();
        $character->setHp($character->getHp() - $result->damageToPlayer);

        if ($battle->isPvp()) {
            $opponentCharacter = $battle->getOpponentCharacter();
            $opponentCharacter->setHp($opponentCharacter->getHp() - $result->damageToOpponent);
        } else {
            $battle->setOpponentHp($battle->getOpponentHp() - $result->damageToOpponent);
        }

        $battle->incrementRoundNumber();

        $round = new BattleRound(
            $battle,
            $battle->getRoundNumber(),
            $this->faceValues($result->playerFaces),
            $this->faceValues($result->opponentFaces),
            $result->damageToOpponent,
            $result->damageToPlayer,
            array_map(static fn (Exchange $e) => [
                'leaderIsPlayer' => $e->leaderIsPlayer,
                'leaderFace' => $e->leaderFace->value,
                'leaderCount' => $e->leaderCount,
                'responderFace' => $e->responderFace?->value,
                'responderCount' => $e->responderCount,
                'damageToPlayer' => $e->damageToPlayer,
                'damageToOpponent' => $e->damageToOpponent,
            ], $result->exchanges),
        );
        $this->entityManager->persist($round);

        $this->resolveOutcome($battle);

        $this->entityManager->flush();

        return $round;
    }

    /**
     * The original simultaneous-reveal engine: apply pre-damage abilities
     * (Reroll), tally attack vs. defense once via CombatResolver, then apply
     * post-damage abilities (UnblockableDamage/DamageMirror). Still used for
     * PvP — see the comment in computeAndApplyRound().
     *
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    private function resolveRoundLegacy(array $playerThrows, array $opponentThrows, AbilityChoice $characterChoice, AbilityChoice $opponentChoice): RoundResult
    {
        $characterActionCount = $this->abilityResolver->countActionFaces($playerThrows);
        $opponentActionCount = $this->abilityResolver->countActionFaces($opponentThrows);

        $playerThrows = $this->abilityResolver->applyPreDamage($playerThrows, $characterChoice);
        $opponentThrows = $this->abilityResolver->applyPreDamage($opponentThrows, $opponentChoice);

        $characterFlipTargets = $this->abilityResolver->effectiveFlipTargets($characterChoice);
        $opponentFlipTargets = $this->abilityResolver->effectiveFlipTargets($opponentChoice);

        $result = $this->combatResolver->resolveRound($playerThrows, $opponentThrows, $characterFlipTargets, $opponentFlipTargets);

        return $this->abilityResolver->applyPostDamage($result, $characterChoice, $opponentChoice, $characterActionCount, $opponentActionCount);
    }

    private function resolveOutcome(Battle $battle): void
    {
        if ($battle->isPvp()) {
            $this->resolvePvpOutcome($battle);

            return;
        }

        $character = $battle->getCharacter();
        if ($battle->getOpponentHp() <= 0) {
            $battle->setStatus(BattleStatus::Won);
            $isEvent = null !== $battle->getEvent();
            $character->addXp($isEvent ? self::EVENT_XP_REWARD : self::XP_REWARD);
            $character->addCoins($isEvent ? self::EVENT_COIN_REWARD : self::COIN_REWARD);
            $this->questService->recordBattleWin($character);
        } elseif ($character->getHp() <= 0) {
            $battle->setStatus(BattleStatus::Lost);
        }
    }

    private function resolvePvpOutcome(Battle $battle): void
    {
        $character = $battle->getCharacter();
        $opponentCharacter = $battle->getOpponentCharacter();

        // Simultaneous KO is broken in `character`'s favour, matching the
        // PvE outcome check above (opponent's death is checked first there too).
        if ($opponentCharacter->getHp() <= 0) {
            $battle->setStatus(BattleStatus::Won);
            $character->addXp(self::PVP_XP_REWARD);
            $character->addCoins(self::PVP_COIN_REWARD);
        } elseif ($character->getHp() <= 0) {
            $battle->setStatus(BattleStatus::Lost);
            $opponentCharacter->addXp(self::PVP_XP_REWARD);
            $opponentCharacter->addCoins(self::PVP_COIN_REWARD);
        }
    }

    /**
     * @param BitFace[] $faces
     *
     * @return string[]
     */
    private function faceValues(array $faces): array
    {
        return array_map(static fn (BitFace $face) => $face->value, $faces);
    }
}
