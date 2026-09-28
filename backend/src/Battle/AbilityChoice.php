<?php

namespace App\Battle;

use App\Enum\AbilityType;

/**
 * One side's choice of how to spend this round's rolled action points.
 * `targets` is only meaningful for AbilityType::Flip/Reroll (both can target
 * a not-yet-activated bit of the OPPONENT) and AbilityType::Destroy (always
 * targets the opponent). `ownTargets` is Flip/Reroll-only, additional to
 * `targets` — the caster's OWN not-yet-activated bit(s) to target alongside
 * (or instead of) the opponent's; AbilityType::Double also targets the
 * caster's own bit, but reads it from `targets`, not `ownTargets` (it never
 * has an opponent option to disambiguate from). Unlike `targets`,
 * `ownTargets` never auto-fills beyond what was explicitly declared (see
 * InteractiveExchangeEngine::applyFlip()/applyReroll()) — silently acting on
 * more of the caster's own bits than they actually chose could turn a good
 * face into a bad one without consent.
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
