<?php

namespace App\Repository;

use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Character>
 */
class CharacterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Character::class);
    }

    /**
     * @return Character[]
     */
    public function findTopByXp(int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.xp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
