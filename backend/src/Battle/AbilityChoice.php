<?php

namespace App\Battle;

use App\Enum\AbilityType;

/**
 * One side's choice of how to spend this round's rolled action points.
 * `targets` is only meaningful for AbilityType::Flip and AbilityType::Destroy
 * (both target specific not-yet-activated bits of the OPPONENT) and
 * AbilityType::Double (targets one of the caster's OWN not-yet-activated bits).
 * `ownTargets` is Flip-only, additional to `targets` — the caster's OWN
 * not-yet-activated bits to flip alongside (or instead of) the opponent's;
 * unlike `targets`, it never auto-fills beyond what was explicitly declared
 * (see InteractiveExchangeEngine::applyFlip()) — silently flipping more of
 * the caster's own bits than they actually chose could turn a good face
 * into a bad one without consent.
 */
final class AbilityChoice
{
    /**
     * @param int[] $targets
     * @param int[] $ownTargets
     */
    public function __construct(
        public readonly AbilityType $ability,
        public readonly array $targets = [],
        public readonly array $ownTargets = [],
    ) {
    }

    /**
     * @param int[] $targets
     * @param int[] $ownTargets
     */
    public static function flip(array $targets = [], array $ownTargets = []): self
    {
        return new self(AbilityType::Flip, $targets, $ownTargets);
    }

    /**
     * @return array{ability: string, targets: int[], ownTargets: int[]}
     */
    public function toArray(): array
    {
        return ['ability' => $this->ability->value, 'targets' => $this->targets, 'ownTargets' => $this->ownTargets];
    }

    /**
     * @param array{ability?: string, targets?: int[], ownTargets?: int[]} $data
     */
    public static function fromArray(array $data): self
    {
        $ability = AbilityType::tryFrom($data['ability'] ?? '') ?? AbilityType::Flip;

        return new self($ability, $data['targets'] ?? [], $data['ownTargets'] ?? []);
    }
}
