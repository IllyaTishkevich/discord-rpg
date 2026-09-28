<?php

namespace App\Repository;

use App\Entity\Character;
use App\Entity\TournamentMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentMatch>
 */
class TournamentMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentMatch::class);
    }

    /**
     * Every match slot this character appears in, as either side or as the
     * recorded winner — used by CharacterService::wipeCharacter(). Removing
     * the whole match row (rather than just nulling this character out of
     * it) also erases the other participant's record of that one match;
     * an accepted trade-off for a "delete my character" feature.
     *
     * @return TournamentMatch[]
     */
    public function findAllFor(Character $character): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.characterA = :character OR m.characterB = :character OR m.winner = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->getResult();
    }
}
