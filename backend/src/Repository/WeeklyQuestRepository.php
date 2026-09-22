<?php

namespace App\Repository;

use App\Entity\WeeklyQuest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeeklyQuest>
 */
class WeeklyQuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyQuest::class);
    }

    public function findOneByWeekStart(\DateTimeImmutable $weekStart): ?WeeklyQuest
    {
        return $this->findOneBy(['weekStart' => $weekStart]);
    }

    public function findCurrent(): ?WeeklyQuest
    {
        return $this->findOneByWeekStart(self::currentWeekStart());
    }

    public static function currentWeekStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('monday this week');
    }
}
