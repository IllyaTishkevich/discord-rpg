<?php

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterEquipment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterEquipment>
 */
class CharacterEquipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterEquipment::class);
    }

    /**
     * @return CharacterEquipment[]
     */
    public function findByCharacter(Character $character): array
    {
        return $this->findBy(['character' => $character]);
    }
}
