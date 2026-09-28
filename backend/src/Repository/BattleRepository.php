<?php

namespace App\Repository;

use App\Entity\Battle;
use App\Entity\Character;
use App\Enum\BattleMode;
use App\Enum\BattleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Battle>
 */
class BattleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Battle::class);
    }

    /**
     * Most recent PvP battle (waiting or in_progress) the given character is
     * part of, on either side — used by the Activity to show the duel lobby
     * / arena instead of the profile screen right after login.
     */
    public function findActivePvpFor(Character $character): ?Battle
    {
        return $this->createQueryBuilder('b')
            ->where('b.mode = :mode')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.character = :character OR b.opponentCharacter = :character')
            ->setParameter('mode', BattleMode::Pvp)
            ->setParameter('statuses', [BattleStatus::Waiting, BattleStatus::InProgress])
            ->setParameter('character', $character)
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every battle this character was ever part of, on either side —
     * CharacterService::wipeCharacter() removes each one via the entity
     * manager (not a bulk DQL delete) so Battle::$rounds' orphanRemoval
     * cascades to BattleRound.
     *
     * @return Battle[]
     */
    public function findAllFor(Character $character): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.character = :character OR b.opponentCharacter = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->getResult();
    }
}
