<?php

namespace App\Repository;

use App\Entity\Monster;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Monster>
 */
class MonsterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monster::class);
    }

    /**
     * The highest-level monster that doesn't exceed the character's level
     * (difficulty progression as the character grows) — or, if every
     * catalog monster is above that level, the lowest-level one available
     * (so a fresh level-1 character still gets *something* rather than
     * nothing). Null only if the catalog is empty.
     */
    public function findForCharacterLevel(int $level): ?Monster
    {
        $notExceeding = $this->createQueryBuilder('m')
            ->where('m.level <= :level')
            ->setParameter('level', $level)
            ->orderBy('m.level', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null !== $notExceeding) {
            return $notExceeding;
        }

        return $this->createQueryBuilder('m')
            ->orderBy('m.level', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
