<?php

namespace App\Repository;

use App\Entity\Bit;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bit>
 */
class BitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bit::class);
    }

    /**
     * @return Bit[]
     */
    public function findByCharacter(Character $character): array
    {
        return $this->findBy(['character' => $character]);
    }
}
