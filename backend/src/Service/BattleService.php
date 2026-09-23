<?php

namespace App\Service;

use App\Battle\AbilityChoice;
use App\Battle\AbilityResolver;
use App\Battle\BitThrow;
use App\Battle\Exchange;
use App\Battle\ExchangeMoveResult;
use App\Battle\ExchangeResolver;
use App\Battle\ExchangeRoundState;
use App\Battle\InteractiveExchangeEngine;
use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\Event;
use App\Entity\Item;
use App\Entity\Monster;
use App\Enum\BattleMode;
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Exception\AbilityNotAvailableException;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Exception\InvalidBattleStateException;
use App\Exception\NoPendingThrowException;
use App\Exception\NotBattleParticipantException;
use App\Repository\MonsterRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application service orchestrating battles: PvE/event fights against a bot
 * opponent, and PvP duels between two real characters. throwRound() always
 * comes first, so the player sees the revealed bits before deciding
 * anything. What follows depends on the surface:
 *
 *  - Interactive PvE/event/PvP (the Activity's Arena): submitExchangeMove(),
 *    one call per lead/respond decision — see docs/COMBAT_V2_DESIGN.md §7-8
 *    and InteractiveExchangeEngine's class docblock. For PvE/event the bot's
 *    side auto-plays synchronously in the same request; for PvP both sides
 *    are real players, each submitting their own lead/respond via separate
 *    requests (submitPvpExchangeMove() — no auto-play, since there's no bot
 *    to drive it, plus a per-move deadline so an idle opponent can't stall
 *    a duel forever, see applyPvpMoveTimeoutIfExpired()).
 *  - Non-interactive PvE/event auto-play (bot text commands): resolveRound()
 *    plays the whole round out in one call via ExchangeResolver, with the
 *    player's side driven by a single upfront AbilityChoice + heuristic
 *    instead of real per-exchange decisions. (Tournament simulation uses
 *    ExchangeResolver directly, bypassing BattleService/Battle entirely.)
 *
 * Combat abilities (docs/BATTLE_RULES.md §3.1): whichever side has rolled
 * "action" faces picks an ability to spend them on — Flip (the original/
 * default), UnblockableDamage, Reroll, or DamageMirror — not a mix. In the
 * interactive flow (PvE/event/PvP alike) the ability is chosen per
 * action-bit move as it's played; in the non-interactive engine it's one
 * upfront choice for the whole round. The PvE/event bot opponent picks
 * uniformly at random from its Monster's granted abilities each time (see
 * pickBotAbility()), falling back to Flip for battles with no catalog
 * monster attached.
 *
 * PvP lifecycle (see docs/BATTLE_ROOM_DESIGN.md): createPvpChallenge()
 * creates the Battle itself in `waiting` status — that row *is* the invite.
 * acceptPvpChallenge() / markReady() move it toward `in_progress`, at which
 * point the first round is thrown automatically.
 */
class BattleService
{
    // Fallback opponent for when the Monster catalog is empty (fresh
    // install before an admin has added anything to it — see
    // startPveBattle()/opponentBitThrows()) or for battles/rows created
    // before the catalog existed. Placeholder balance (see docs/ROADMAP.md,
    // Game Design backlog) — the monster deliberately rolls advantage less
    // often than a well-built player character, so it rarely leads the
    // exchange sequence (docs/COMBAT_V2_DESIGN.md §2).
    private const MONSTER_NAME = 'Тренировочный голем';
    private const MONSTER_HP = 20;
    // Each entry: [faceA, faceB, advantageA, advantageB].
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
        private readonly ExchangeResolver $exchangeResolver,
        private readonly InteractiveExchangeEngine $interactiveEngine,
        private readonly AbilityResolver $abilityResolver,
        private readonly QuestService $questService,
        private readonly MonsterRepository $monsterRepository,
        private readonly LootService $lootService,
    ) {
    }

    public function startPveBattle(Character $character): Battle
    {
        if ($character->getEnergy() < 1) {
            throw new InsufficientEnergyException('Not enough energy to start a battle.');
        }

        $character->setEnergy($character->getEnergy() - 1);

        $monster = $this->monsterRepository->findForCharacterLevel($character->getLevel());
        $battle = null !== $monster
            ? new Battle($character, $monster->getName(), $monster->getMaxHp())
            : new Battle($character, self::MONSTER_NAME, self::MONSTER_HP);
        if (null !== $monster) {
            $battle->setOpponentMonster($monster);
        }
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
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB(), $bit->getMultiplierA(), $bit->getMultiplierB()),
            $battle->getCharacter()->getAllBits(),
        );
        $opponentThrows = $battle->isPvp()
            ? array_map(
                static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB(), $bit->getMultiplierA(), $bit->getMultiplierB()),
                $battle->getOpponentCharacter()->getAllBits(),
            )
            : $this->opponentBitThrows($battle);

        $battle->setPendingThrows(
            array_map(static fn (BitThrow $t) => $t->toArray(), $playerThrows),
            array_map(static fn (BitThrow $t) => $t->toArray(), $opponentThrows),
        );

        if ($battle->isPvp()) {
            // Real players on both sides — nobody auto-plays (see
            // InteractiveExchangeEngine::startPvpRound()); start the
            // move-deadline clock for whoever leads first.
            $state = $this->interactiveEngine->startPvpRound($playerThrows, $opponentThrows);
            $battle->setPendingExchangeState($state->toArray());
            $this->refreshPvpMoveDeadline($battle);
        } else {
            // Interactive step-by-step flow (docs/COMBAT_V2_DESIGN.md §7-8) —
            // startRound() may already auto-play the bot's opening move (or,
            // in the near-impossible case of a 0-bit character, resolve the
            // entire round instantly) — apply any such exchanges now.
            ['state' => $roundState, 'exchanges' => $exchanges] = $this->interactiveEngine->startRound($playerThrows, $opponentThrows, $this->pickBotAbility($battle));
            foreach ($exchanges as $exchange) {
                $this->applyExchangeDamage($battle, $exchange);
            }
            $battle->setPendingExchangeState($roundState->toArray());
        }

        $this->entityManager->flush();

        return $this->throwResultFromThrows($battle, $playerThrows, $opponentThrows);
    }

    /**
     * @return BitThrow[]
     */
    private function opponentBitThrows(Battle $battle): array
    {
        $monster = $battle->getOpponentMonster();
        $monsterBits = null !== $monster ? $monster->getBits()->toArray() : [];
        if ([] === $monsterBits) {
            // No catalog monster (legacy/empty catalog), or one with no bits
            // configured yet — an admin-editable monster with zero bits
            // would otherwise leave it unable to act at all.
            return array_map(
                static fn (array $bit) => BitThrow::random($bit[0], $bit[1], $bit[2], $bit[3]),
                self::MONSTER_BITS,
            );
        }

        return array_map(
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB(), $bit->getMultiplierA(), $bit->getMultiplierB()),
            $monsterBits,
        );
    }

    /**
     * The bot's ability choice for this battle — uniformly at random from
     * its Monster's granted abilities each time it's asked, or Flip if the
     * battle has no catalog monster (or that monster grants none). Targets
     * are always left empty: only Flip reads them, and the exchange
     * engines' own flip-target heuristic already picks sensible ones when
     * none are declared.
     */
    private function pickBotAbility(Battle $battle): AbilityChoice
    {
        $monster = $battle->getOpponentMonster();
        $abilities = null !== $monster ? $monster->getAbilities()->toArray() : [];
        if ([] === $abilities) {
            return AbilityChoice::flip();
        }

        return new AbilityChoice($abilities[array_rand($abilities)]->getType(), []);
    }

    /**
     * A fresh snapshot of the currently pending throw/exchange — same shape
     * throwRound() returns, but without re-rolling anything. Used both by
     * throwRound()'s own idempotent branch and, publicly, by
     * BattleController::show() so a polling PvP viewer can see faces/
     * used/turn/incomingMove update as the other side acts, without a
     * throw of their own.
     */
    public function currentThrowResult(Battle $battle): ThrowResult
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());

        return $this->throwResultFromThrows($battle, $playerThrows, $opponentThrows);
    }

    /**
     * PvP-only: applies the move-timeout check (see
     * applyPvpMoveTimeoutIfExpired()) as a side effect of a plain read (GET
     * /{id}) — so a duel doesn't just silently stall forever if one side
     * goes idle and never sends another request of their own. No-op for
     * PvE/event (bot never leaves anyone waiting) or a battle with no
     * pending throw at all.
     */
    public function syncPvpExchangeState(Battle $battle): void
    {
        if (!$battle->isPvp() || BattleStatus::InProgress !== $battle->getStatus() || !$battle->hasPendingThrow()) {
            return;
        }

        $stateArray = $battle->getPendingExchangeState();
        if (null === $stateArray) {
            return;
        }

        $state = $this->applyPvpMoveTimeoutIfExpired($battle, ExchangeRoundState::fromArray($stateArray));

        if ($this->isPvpBattleKnockedOut($battle) || $state->isOver()) {
            $this->finalizeInteractiveRound($battle, $state);
        } else {
            $this->entityManager->flush();
        }
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
        if (null !== $stateArray) {
            $state = ExchangeRoundState::fromArray($stateArray);
            // Fixed frame (always "battle.character"'s status, same as PvE
            // always implicitly was) — see BattleSerializer for how a PvP
            // opponent-side viewer derives their own status from this.
            $turn = $battle->isPvp() ? $this->interactiveEngine->turnForSide($state, true) : $this->interactiveEngine->currentTurn($state);
            $incomingMove = null !== $state->pendingLeaderMove ? $this->interactiveEngine->getIncomingMove($state) : null;
            $playerUsed = $state->playerUsed;
            $opponentUsed = $state->opponentUsed;
        }

        return new ThrowResult($playerThrows, $opponentThrows, $playerActionCount, $turn, $incomingMove, $playerUsed, $opponentUsed);
    }

    /**
     * PvE/event only, non-interactive whole-round-at-once path (bot text
     * commands) — PvP is always interactive now, via submitPvpExchangeMove().
     */
    public function resolveRound(Battle $battle, AbilityChoice $characterChoice): BattleRound
    {
        if ($battle->isPvp()) {
            throw new InvalidBattleStateException('resolveRound() is PvE/event-only — PvP always goes through the interactive exchange flow.');
        }
        if (!$battle->hasPendingThrow()) {
            throw new NoPendingThrowException('No pending throw to resolve — call throwRound() first.');
        }

        $rolledActionCount = $this->abilityResolver->countActionFaces(array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows()));
        $this->abilityResolver->assertAffordable($characterChoice, $rolledActionCount);

        // PvE/event opponent (the bot) — see pickBotAbility().
        return $this->computeAndApplyRound($battle, $characterChoice, $this->pickBotAbility($battle));
    }

    /**
     * One step of the interactive exchange flow (docs/COMBAT_V2_DESIGN.md
     * §7-8): either leading (your turn to commit bits) or responding to the
     * other side's already-committed lead move, inferred from the battle's
     * own pending state rather than trusted from the client. PvP is routed
     * to submitPvpExchangeMove() below — everything from here down is the
     * original PvE/event path (bot auto-plays its side synchronously),
     * unchanged. See InteractiveExchangeEngine's class docblock for the
     * underlying call pattern this wraps.
     *
     * @param int[] $indices
     */
    public function submitExchangeMove(Battle $battle, Character $viewer, array $indices, ?AbilityChoice $ability): ExchangeMoveResult
    {
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }

        $stateArray = $battle->getPendingExchangeState();
        if (null === $stateArray) {
            throw new NoPendingThrowException('No exchange in progress — call throwRound() first.');
        }
        $state = ExchangeRoundState::fromArray($stateArray);

        if ($battle->isPvp()) {
            return $this->submitPvpExchangeMove($battle, $viewer, $state, $indices, $ability);
        }

        $turn = $this->interactiveEngine->currentTurn($state);
        if ('over' === $turn) {
            throw new InvalidBattleStateException('This round has already been fully played out.');
        }
        // Only relevant when leading with an action-face move (mirrors
        // InteractiveExchangeEngine's own `BitFace::Action === $face` gate)
        // — a response can only ever be a defense bit or a pass now (see
        // docs/COMBAT_V2_DESIGN.md §4), never action, so it should never be
        // blocked by a missing ability it isn't even trying to use.
        $selectedFace = [] !== $indices ? ($state->playerThrows[$indices[0]] ?? null)?->thrownFace : null;
        if (null !== $ability && BitFace::Action === $selectedFace && 'lead' === $turn) {
            $this->assertAbilityAvailable($battle->getCharacter(), $ability);
        }

        $botChoice = $this->pickBotAbility($battle);
        $newExchanges = [];

        ['state' => $state, 'exchange' => $exchange] = 'lead' === $turn
            ? $this->interactiveEngine->submitLead($state, $indices, $ability, $botChoice)
            : $this->interactiveEngine->submitRespond($state, $indices);
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

        // Faces (and their multipliers) can change mid-round (Flip) and are
        // captured here regardless of which branch below runs — the client
        // needs the current values, not just the ones from the original throw.
        $playerFaces = array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows);
        $opponentFaces = array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows);
        $playerMultipliers = array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->playerThrows);
        $opponentMultipliers = array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->opponentThrows);

        if ($knockedOut || $state->isOver()) {
            $round = $this->finalizeInteractiveRound($battle, $state);

            return new ExchangeMoveResult(
                roundComplete: true,
                newExchanges: $newExchanges,
                playerFaces: $playerFaces,
                opponentFaces: $opponentFaces,
                playerUsed: $state->playerUsed,
                opponentUsed: $state->opponentUsed,
                playerMultipliers: $playerMultipliers,
                opponentMultipliers: $opponentMultipliers,
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
            playerMultipliers: $playerMultipliers,
            opponentMultipliers: $opponentMultipliers,
            turn: $turnNow,
            incomingMove: null !== $state->pendingLeaderMove ? $this->interactiveEngine->getIncomingMove($state) : null,
        );
    }

    /**
     * PvP-only half of submitExchangeMove(): both sides are real players,
     * so — unlike PvE/event — nobody auto-plays the other side. $isPlayerSide
     * below always means "$viewer's own side" (engine terms), determined via
     * requireSide(); it has nothing to do with which real duelist is
     * battle.character vs battle.opponentCharacter.
     *
     * @param int[] $indices
     */
    private function submitPvpExchangeMove(Battle $battle, Character $viewer, ExchangeRoundState $state, array $indices, ?AbilityChoice $ability): ExchangeMoveResult
    {
        $isPlayerSide = !$this->requireSide($battle, $viewer);

        $state = $this->applyPvpMoveTimeoutIfExpired($battle, $state);
        if ($this->isPvpBattleKnockedOut($battle) || $state->isOver()) {
            return $this->finalizePvpMove($battle, $state, []);
        }

        $turn = $this->interactiveEngine->turnForSide($state, $isPlayerSide);
        if ('over' === $turn) {
            throw new InvalidBattleStateException('This round has already been fully played out.');
        }
        if ('wait' === $turn) {
            throw new InvalidBattleStateException('It is not your turn right now — waiting on your opponent.');
        }

        $ownThrows = $isPlayerSide ? $state->playerThrows : $state->opponentThrows;
        $selectedFace = [] !== $indices ? ($ownThrows[$indices[0]] ?? null)?->thrownFace : null;
        if (null !== $ability && BitFace::Action === $selectedFace && 'lead' === $turn) {
            $this->assertAbilityAvailable($viewer, $ability);
        }

        $exchange = null;
        if ('lead' === $turn) {
            $state = [] === $indices
                ? $this->interactiveEngine->passPvpLead($state, $isPlayerSide)
                : $this->interactiveEngine->submitPvpLead($state, $isPlayerSide, $indices, $ability);
        } else {
            ['state' => $state, 'exchange' => $exchange] = $this->interactiveEngine->submitPvpRespond($state, $isPlayerSide, $indices);
        }

        $newExchanges = [];
        if (null !== $exchange) {
            $newExchanges[] = $exchange;
            $this->applyPvpExchangeDamage($battle, $exchange);
        }

        if ($this->isPvpBattleKnockedOut($battle) || $state->isOver()) {
            return $this->finalizePvpMove($battle, $state, $newExchanges);
        }

        $battle->setPendingExchangeState($state->toArray());
        $this->refreshPvpMoveDeadline($battle);
        $this->entityManager->flush();

        $turnNow = $this->interactiveEngine->turnForSide($state, true);

        return new ExchangeMoveResult(
            roundComplete: false,
            newExchanges: $newExchanges,
            playerFaces: array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows),
            opponentFaces: array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows),
            playerUsed: $state->playerUsed,
            opponentUsed: $state->opponentUsed,
            playerMultipliers: array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->playerThrows),
            opponentMultipliers: array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->opponentThrows),
            turn: $turnNow,
            incomingMove: null !== $state->pendingLeaderMove ? $this->interactiveEngine->getIncomingMove($state) : null,
        );
    }

    private function finalizePvpMove(Battle $battle, ExchangeRoundState $state, array $newExchanges): ExchangeMoveResult
    {
        $round = $this->finalizeInteractiveRound($battle, $state);

        return new ExchangeMoveResult(
            roundComplete: true,
            newExchanges: $newExchanges,
            playerFaces: array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows),
            opponentFaces: array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows),
            playerUsed: $state->playerUsed,
            opponentUsed: $state->opponentUsed,
            playerMultipliers: array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->playerThrows),
            opponentMultipliers: array_map(static fn (BitThrow $t) => $t->thrownMultiplier, $state->opponentThrows),
            round: $round,
        );
    }

    /**
     * If the current move-deadline has passed, whichever side currently
     * owes a move is treated as passing it — a lazy check (no worker/cron),
     * consistent with the project's polling-only sync model (see
     * docs/BATTLE_ROOM_DESIGN.md §6). A "pass" while responding means no
     * response (full damage from the incoming move, same as an explicit
     * empty submission); a "pass" while leading just hands initiative to
     * the other side without consuming any bits.
     */
    private function applyPvpMoveTimeoutIfExpired(Battle $battle, ExchangeRoundState $state): ExchangeRoundState
    {
        if (!$battle->isRoundDeadlinePassed()) {
            return $state;
        }

        foreach ([true, false] as $isPlayerSide) {
            $turn = $this->interactiveEngine->turnForSide($state, $isPlayerSide);
            if ('respond' === $turn) {
                ['state' => $state, 'exchange' => $exchange] = $this->interactiveEngine->submitPvpRespond($state, $isPlayerSide, []);
                $this->applyPvpExchangeDamage($battle, $exchange);
                break;
            }
            if ('lead' === $turn) {
                $state = $this->interactiveEngine->passPvpLead($state, $isPlayerSide);
                break;
            }
        }

        if (!$this->isPvpBattleKnockedOut($battle) && !$state->isOver()) {
            $battle->setPendingExchangeState($state->toArray());
            $this->refreshPvpMoveDeadline($battle);
        }

        return $state;
    }

    private function refreshPvpMoveDeadline(Battle $battle): void
    {
        $battle->setRoundDeadlineAt(new \DateTimeImmutable(sprintf('+%d seconds', self::PVP_ROUND_TIMEOUT_SECONDS)));
    }

    private function applyPvpExchangeDamage(Battle $battle, array $exchange): void
    {
        $character = $battle->getCharacter();
        $opponentCharacter = $battle->getOpponentCharacter();
        $character->setHp($character->getHp() - $exchange['damageToPlayer']);
        $opponentCharacter->setHp($opponentCharacter->getHp() - $exchange['damageToOpponent']);
    }

    private function isPvpBattleKnockedOut(Battle $battle): bool
    {
        return $battle->getCharacter()->getHp() <= 0 || $battle->getOpponentCharacter()->getHp() <= 0;
    }

    private function applyExchangeDamage(Battle $battle, array $exchange): void
    {
        $character = $battle->getCharacter();
        $character->setHp($character->getHp() - $exchange['damageToPlayer']);
        $battle->setOpponentHp($battle->getOpponentHp() - $exchange['damageToOpponent']);
    }

    /**
     * Which abilities a character can pick from is now configurable per
     * class/character/equipment (see Character::hasAbilityType()) instead
     * of every ability being available to everyone — enforced here rather
     * than trusting the client to only ever offer what it was granted.
     */
    private function assertAbilityAvailable(Character $character, AbilityChoice $choice): void
    {
        if (!$character->hasAbilityType($choice->ability)) {
            throw new AbilityNotAvailableException(\sprintf(
                'This character does not have the "%s" ability.',
                $choice->ability->value,
            ));
        }
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

        // Resolved before building BattleRound so its dropped-items snapshot
        // (see BattleRound::$droppedItems) can be attached at construction
        // time, same as every other round-outcome field below it.
        $droppedItems = $this->resolveOutcome($battle);

        $round = new BattleRound(
            $battle,
            $battle->getRoundNumber(),
            array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->playerThrows),
            array_map(static fn (BitThrow $t) => $t->thrownFace->value, $state->opponentThrows),
            array_sum(array_column($state->exchanges, 'damageToOpponent')),
            array_sum(array_column($state->exchanges, 'damageToPlayer')),
            $state->exchanges,
            $this->serializeDroppedItems($droppedItems),
        );
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round;
    }

    /**
     * @param Item[] $items
     *
     * @return array{name: string, iconName: ?string}[]
     */
    private function serializeDroppedItems(array $items): array
    {
        return array_map(static fn (Item $item) => ['name' => $item->getName(), 'iconName' => $item->getIconName()], $items);
    }

    /**
     * PvE/event only (via resolveRound()) — PvP is fully interactive now
     * (submitPvpExchangeMove()), so this no longer needs an isPvp() branch.
     */
    private function computeAndApplyRound(Battle $battle, AbilityChoice $characterChoice, AbilityChoice $opponentChoice): BattleRound
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());
        $battle->setPendingThrows(null, null);

        $result = $this->exchangeResolver->resolveRound($playerThrows, $opponentThrows, $characterChoice, $opponentChoice);

        $character = $battle->getCharacter();
        $character->setHp($character->getHp() - $result->damageToPlayer);
        $battle->setOpponentHp($battle->getOpponentHp() - $result->damageToOpponent);

        $battle->incrementRoundNumber();

        // Resolved before building BattleRound so its dropped-items snapshot
        // (see BattleRound::$droppedItems) can be attached at construction
        // time, same as every other round-outcome field below it.
        $droppedItems = $this->resolveOutcome($battle);

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
            $this->serializeDroppedItems($droppedItems),
        );
        $this->entityManager->persist($round);

        $this->entityManager->flush();

        return $round;
    }


    /**
     * @return Item[] items dropped this round (only ever non-empty for a
     *                 PvE/event win — PvP has no monster to drop anything)
     */
    private function resolveOutcome(Battle $battle): array
    {
        if ($battle->isPvp()) {
            $this->resolvePvpOutcome($battle);

            return [];
        }

        $character = $battle->getCharacter();
        if ($battle->getOpponentHp() <= 0) {
            $battle->setStatus(BattleStatus::Won);
            $isEvent = null !== $battle->getEvent();
            $character->addXp($isEvent ? self::EVENT_XP_REWARD : self::XP_REWARD);
            $character->addCoins($isEvent ? self::EVENT_COIN_REWARD : self::COIN_REWARD);
            $this->questService->recordBattleWin($character);

            return $this->lootService->rollDrops($character, $battle->getOpponentMonster());
        }
        if ($character->getHp() <= 0) {
            $battle->setStatus(BattleStatus::Lost);
        }

        return [];
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
