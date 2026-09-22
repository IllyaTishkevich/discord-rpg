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
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Exception\NoPendingThrowException;
use App\Repository\BitRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application service orchestrating PvE battles: starting a fight against
 * the training bot, then resolving each round in two steps so the player
 * can see the revealed bits before spending any "action" faces they rolled:
 *
 *  1. throwRound()   — both sides' bits are thrown and stored on the Battle
 *  2. resolveRound()  — the player's action targets are applied, damage is
 *                        computed via CombatResolver, and the pending throw
 *                        is cleared
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
        $battle = new Battle($character, $event->getMonsterName(), $event->getMonsterHp());
        $battle->setEvent($event);
        $this->entityManager->persist($battle);
        $this->entityManager->flush();

        return $battle;
    }

    public function throwRound(Battle $battle): ThrowResult
    {
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }

        $playerThrows = array_map(
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB()),
            $this->bitRepository->findByCharacter($battle->getCharacter()),
        );
        $opponentThrows = array_map(
            static fn (array $faces) => BitThrow::random($faces[0], $faces[1]),
            self::MONSTER_BITS,
        );

        $battle->setPendingThrows(
            array_map(static fn (BitThrow $t) => $t->toArray(), $playerThrows),
            array_map(static fn (BitThrow $t) => $t->toArray(), $opponentThrows),
        );
        $this->entityManager->flush();

        $playerActionCount = \count(array_filter($playerThrows, static fn (BitThrow $t) => BitFace::Action === $t->thrownFace));

        return new ThrowResult($playerThrows, $opponentThrows, $playerActionCount);
    }

    /**
     * @param int[] $playerActionTargets indices into the opponent's thrown bits to flip
     */
    public function resolveRound(Battle $battle, array $playerActionTargets): BattleRound
    {
        if (!$battle->hasPendingThrow()) {
            throw new NoPendingThrowException('No pending throw to resolve — call throwRound() first.');
        }

        $playerThrows = array_map(BitThrow::fromArray(...), $battle->getPendingPlayerThrows());
        $opponentThrows = array_map(BitThrow::fromArray(...), $battle->getPendingOpponentThrows());
        $battle->setPendingThrows(null, null);

        $result = $this->combatResolver->resolveRound($playerThrows, $opponentThrows, $playerActionTargets);

        $character = $battle->getCharacter();
        $character->setHp($character->getHp() - $result->damageToPlayer);
        $battle->setOpponentHp($battle->getOpponentHp() - $result->damageToOpponent);
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

        $this->resolveOutcome($battle, $character);

        $this->entityManager->flush();

        return $round;
    }

    private function resolveOutcome(Battle $battle, Character $character): void
    {
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
