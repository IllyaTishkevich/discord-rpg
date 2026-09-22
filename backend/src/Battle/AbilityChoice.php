<?php

namespace App\Battle;

use App\Enum\AbilityType;

/**
 * One side's choice of how to spend this round's rolled action points.
 * `targets` is only meaningful for AbilityType::Flip.
 */
final class AbilityChoice
{
    /**
     * @param int[] $targets
     */
    public function __construct(
        public readonly AbilityType $ability,
        public readonly array $targets = [],
    ) {
    }

    /**
     * @param int[] $targets
     */
    public static function flip(array $targets = []): self
    {
        return new self(AbilityType::Flip, $targets);
    }

    /**
     * @return array{ability: string, targets: int[]}
     */
    public function toArray(): array
    {
        return ['ability' => $this->ability->value, 'targets' => $this->targets];
    }

    /**
     * @param array{ability?: string, targets?: int[]} $data
     */
    public static function fromArray(array $data): self
    {
        $ability = AbilityType::tryFrom($data['ability'] ?? '') ?? AbilityType::Flip;

        return new self($ability, $data['targets'] ?? []);
    }
}
