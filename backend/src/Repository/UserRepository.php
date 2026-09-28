<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByDiscordId(string $discordId): ?User
    {
        return $this->findOneBy(['discordId' => $discordId]);
    }

    /**
     * @param string[] $discordIds
     *
     * @return User[]
     */
    public function findByDiscordIds(array $discordIds): array
    {
        if ([] === $discordIds) {
            return [];
        }

        return $this->findBy(['discordId' => $discordIds]);
    }
}
