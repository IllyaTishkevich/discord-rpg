<?php

namespace App\Service;

use App\Battle\BitThrow;
use App\Battle\CombatResolver;
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
use App\Repository\BitRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application service orchestrating battles: PvE/event fights against a bot
 * opponent, and PvP duels between two real characters. A round always goes
 * through the same two steps, so the player can see the revealed bits
 * before spending any "action" faces they rolled:
 *
 *  1. throwRound()  — both sides' bits are thrown and stored on the Battle
 *  2. resolveRound() (PvE/event) or submitActions() (PvP) — action targets
 *     are applied, damage is computed via CombatResolver, pending state is
 *     cleared
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
    private const MONSTER_BITS = [
        [BitFace::Attack, BitFace::Defense],
        [BitFace::Attack, BitFace::Action],
        [BitFace::Defense, BitFace::Defense],
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
        private readonly BitRepository $bitRepository,
        private readonly CombatResolver $combatResolver,
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
     * @param int[] $targets indices into the opponent's thrown bits to flip
     *
     * @return BattleRound|null null while waiting on the other side to submit;
     *                          the resolved round once both sides are in
     */
    public function submitActions(Battle $battle, Character $viewer, array $targets): ?BattleRound
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

        $battle->submitActionTargets($this->requireSide($battle, $viewer), $targets);
        $this->applyRoundTimeoutIfExpired($battle);

        if (!$battle->bothActionTargetsSubmitted()) {
            $this->entityManager->flush();

            return null;
        }

        $characterTargets = $battle->getPendingCharacterActionTargets() ?? [];
        $opponentTargets = $battle->getPendingOpponentActionTargets() ?? [];
        $battle->clearPendingActionTargets();

        return $this->computeAndApplyRound($battle, $characterTargets, $opponentTargets);
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

        if (null === $battle->getPendingCharacterActionTargets()) {
            $battle->submitActionTargets(false, []);
        }
        if (null === $battle->getPendingOpponentActionTargets()) {
            $battle->submitActionTargets(true, []);
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
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB()),
            $this->bitRepository->findByCharacter($battle->getCharacter()),
        );
        $opponentThrows = $battle->isPvp()
            ? array_map(
                static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB()),
                $this->bitRepository->findByCharacter($battle->getOpponentCharacter()),
            )
            : array_map(
                static fn (array $faces) => BitThrow::random($faces[0], $faces[1]),
                self::MONSTER_BITS,
            );

        $battle->setPendingThrows(
            array_map(static fn (BitThrow $t) => $t->toArray(), $playerThrows),
            array_map(static fn (BitThrow $t) => $t->toArray(), $opponentThrows),
        );
        $battle->setRoundDeadlineAt($battle->isPvp() ? new \DateTimeImmutable(sprintf('+%d seconds', self::PVP_ROUND_TIMEOUT_SECONDS)) : null);
        $this->entityManager->flush();

        return $this->throwResultFromThrows($playerThrows, $opponentThrows);
    }

    private function currentThrowResult(Battle $battle): ThrowResult
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());

        return $this->throwResultFromThrows($playerThrows, $opponentThrows);
    }

    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    private function throwResultFromThrows(array $playerThrows, array $opponentThrows): ThrowResult
    {
        $playerActionCount = \count(array_filter($playerThrows, static fn (BitThrow $t) => BitFace::Action === $t->thrownFace));

        return new ThrowResult($playerThrows, $opponentThrows, $playerActionCount);
    }

    /**
     * PvE/event only — PvP rounds go through submitActions() instead, since
     * both real sides must submit before damage can be computed.
     *
     * @param int[] $playerActionTargets indices into the opponent's thrown bits to flip
     */
    public function resolveRound(Battle $battle, array $playerActionTargets): BattleRound
    {
        if ($battle->isPvp()) {
            throw new InvalidBattleStateException('resolveRound() is PvE/event-only — use submitActions() for PvP.');
        }
        if (!$battle->hasPendingThrow()) {
            throw new NoPendingThrowException('No pending throw to resolve — call throwRound() first.');
        }

        return $this->computeAndApplyRound($battle, $playerActionTargets, null);
    }

    /**
     * @param int[]      $characterActionTargets
     * @param int[]|null $opponentActionTargets  null for PvE/event (BotActionStrategy decides), a real
     *                                           array for PvP (the second player's own choice)
     */
    private function computeAndApplyRound(Battle $battle, array $characterActionTargets, ?array $opponentActionTargets): BattleRound
    {
        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());
        $battle->setPendingThrows(null, null);
        $battle->setRoundDeadlineAt(null);

        $result = $this->combatResolver->resolveRound($playerThrows, $opponentThrows, $characterActionTargets, $opponentActionTargets);

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
        );
        $this->entityManager->persist($round);

        $this->resolveOutcome($battle);

        $this->entityManager->flush();

        return $round;
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
