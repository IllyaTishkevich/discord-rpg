<?php

namespace App\Repository;

use App\Entity\Tournament;
use App\Enum\TournamentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tournament>
 */
class TournamentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tournament::class);
    }

    public function findOpenForRegistration(): ?Tournament
    {
        return $this->findOneBy(['status' => TournamentStatus::Registration], ['id' => 'DESC']);
    }

    /**
     * Most recently created tournament regardless of status — used to view
     * a bracket after it has finished, once it's no longer "open".
     */
    public function findMostRecent(): ?Tournament
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
