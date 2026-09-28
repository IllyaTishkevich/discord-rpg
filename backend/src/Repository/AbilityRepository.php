<?php

namespace App\Repository;

use App\Entity\Ability;
use App\Enum\AbilityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ability>
 */
class AbilityRepository extends ServiceEntityRepository
{
    /**
     * @var array<string, int|null>|null
     */
    private ?array $costCache = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ability::class);
    }

    /**
     * The authoritative action-point cost for $type — Ability::$actionCost
     * from the catalog row, cached for the lifetime of this
     * (request-scoped) repository instance since it's read repeatedly
     * while resolving a single battle move. Falls back to the type's own
     * fixedCost() only if the catalog row is somehow missing (a type
     * added to the enum before its catalog row exists yet).
     */
    public function costFor(AbilityType $type): ?int
    {
        $this->costCache ??= $this->buildCostCache();

        return \array_key_exists($type->value, $this->costCache) ? $this->costCache[$type->value] : $type->fixedCost();
    }

    /**
     * @return array<string, int|null>
     */
    private function buildCostCache(): array
    {
        $map = [];
        foreach ($this->findAll() as $ability) {
            $map[$ability->getType()->value] = $ability->getActionCost();
        }

        return $map;
    }
}
