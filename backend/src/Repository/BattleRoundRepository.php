<?php

namespace App\Repository;

use App\Entity\Battle;
use App\Entity\BattleRound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BattleRound>
 */
class BattleRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BattleRound::class);
    }

    /**
     * @return BattleRound[]
     */
    public function findByBattle(Battle $battle): array
    {
        return $this->findBy(['battle' => $battle]);
    }
}
