<?php

namespace App\Repository;

use App\Entity\Character;
use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentEntry>
 */
class TournamentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentEntry::class);
    }

    public function findOneByTournamentAndCharacter(Tournament $tournament, Character $character): ?TournamentEntry
    {
        return $this->findOneBy(['tournament' => $tournament, 'character' => $character]);
    }
}
