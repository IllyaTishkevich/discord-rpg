<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * The result of throwing a single two-sided bit: its two possible faces
 * (each independently flagged as carrying "advantage" or not — see
 * docs/COMBAT_V2_DESIGN.md §1) and which one is currently showing.
 */
final class BitThrow
{
    public function __construct(
        public readonly BitFace $faceA,
        public readonly BitFace $faceB,
        public readonly bool $advantageA,
        public readonly bool $advantageB,
        public readonly BitFace $thrownFace,
        public readonly bool $thrownAdvantage,
    ) {
    }

    public static function random(BitFace $faceA, BitFace $faceB, bool $advantageA = false, bool $advantageB = false): self
    {
        $isA = random_int(0, 1) === 0;

        return new self($faceA, $faceB, $advantageA, $advantageB, $isA ? $faceA : $faceB, $isA ? $advantageA : $advantageB);
    }

    /**
     * @return array{faceA: string, faceB: string, advantageA: bool, advantageB: bool, thrownFace: string, thrownAdvantage: bool}
     */
    public function toArray(): array
    {
        return [
            'faceA' => $this->faceA->value,
            'faceB' => $this->faceB->value,
            'advantageA' => $this->advantageA,
            'advantageB' => $this->advantageB,
            'thrownFace' => $this->thrownFace->value,
            'thrownAdvantage' => $this->thrownAdvantage,
        ];
    }

    /**
     * @param array{faceA: string, faceB: string, advantageA?: bool, advantageB?: bool, thrownFace: string, thrownAdvantage?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            BitFace::from($data['faceA']),
            BitFace::from($data['faceB']),
            $data['advantageA'] ?? false,
            $data['advantageB'] ?? false,
            BitFace::from($data['thrownFace']),
            $data['thrownAdvantage'] ?? false,
        );
    }

    /**
     * Turns the coin over to its other face — the effect of an opponent's
     * "action" flip. Identifying "the other side" by (face, advantage) pair
     * rather than just face value matters when both sides show the same
     * face type but differ only in advantage (e.g. an "attack+advantage /
     * attack" bit) — comparing thrownFace alone would be ambiguous there.
     */
    public function flipped(): self
    {
        $isCurrentlyA = $this->thrownFace === $this->faceA && $this->thrownAdvantage === $this->advantageA;
        $newIsA = !$isCurrentlyA;

        return new self(
            $this->faceA,
            $this->faceB,
            $this->advantageA,
            $this->advantageB,
            $newIsA ? $this->faceA : $this->faceB,
            $newIsA ? $this->advantageA : $this->advantageB,
        );
    }
}
