<?php

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterQuestProgress;
use App\Entity\WeeklyQuest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterQuestProgress>
 */
class CharacterQuestProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterQuestProgress::class);
    }

    public function findOneByCharacterAndQuest(Character $character, WeeklyQuest $quest): ?CharacterQuestProgress
    {
        return $this->findOneBy(['character' => $character, 'quest' => $quest]);
    }
}
