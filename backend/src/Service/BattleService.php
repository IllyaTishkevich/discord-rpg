<?php

namespace App\Service;

use App\Battle\BitThrow;
use App\Battle\CombatResolver;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Bit;
use App\Entity\Character;
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Repository\BitRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application service orchestrating PvE battles: starting a fight against
 * the training bot and resolving individual rounds via CombatResolver.
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
    private const XP_REWARD = 10;
    private const COIN_REWARD = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BitRepository $bitRepository,
        private readonly CombatResolver $combatResolver,
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
     * @param int[] $playerActionTargets indices into the opponent's thrown bits to flip
     */
    public function playRound(Battle $battle, array $playerActionTargets): BattleRound
    {
        if (BattleStatus::InProgress !== $battle->getStatus()) {
            throw new BattleAlreadyFinishedException('This battle has already finished.');
        }

        $character = $battle->getCharacter();

        $playerThrows = array_map(
            static fn (Bit $bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB()),
            $this->bitRepository->findByCharacter($character),
        );
        $opponentThrows = array_map(
            static fn (array $faces) => BitThrow::random($faces[0], $faces[1]),
            self::MONSTER_BITS,
        );

        $result = $this->combatResolver->resolveRound($playerThrows, $opponentThrows, $playerActionTargets);

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
            $character->addXp(self::XP_REWARD);
            $character->addCoins(self::COIN_REWARD);
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
