<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * The result of throwing a single two-sided bit: its two possible faces
 * (each independently flagged as carrying "advantage" or not, and worth a
 * "multiplier" amount — see docs/COMBAT_V2_DESIGN.md §1) and which one is
 * currently showing.
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
        public readonly int $multiplierA = 1,
        public readonly int $multiplierB = 1,
        public readonly int $thrownMultiplier = 1,
    ) {
    }

    public static function random(
        BitFace $faceA,
        BitFace $faceB,
        bool $advantageA = false,
        bool $advantageB = false,
        int $multiplierA = 1,
        int $multiplierB = 1,
    ): self {
        $isA = random_int(0, 1) === 0;

        return new self(
            $faceA,
            $faceB,
            $advantageA,
            $advantageB,
            $isA ? $faceA : $faceB,
            $isA ? $advantageA : $advantageB,
            $multiplierA,
            $multiplierB,
            $isA ? $multiplierA : $multiplierB,
        );
    }

    /**
     * @return array{faceA: string, faceB: string, advantageA: bool, advantageB: bool, thrownFace: string, thrownAdvantage: bool, multiplierA: int, multiplierB: int, thrownMultiplier: int}
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
            'multiplierA' => $this->multiplierA,
            'multiplierB' => $this->multiplierB,
            'thrownMultiplier' => $this->thrownMultiplier,
        ];
    }

    /**
     * @param array{faceA: string, faceB: string, advantageA?: bool, advantageB?: bool, thrownFace: string, thrownAdvantage?: bool, multiplierA?: int, multiplierB?: int, thrownMultiplier?: int} $data
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
            // Absent in JSON persisted before this field existed (an
            // in-flight battle's pendingExchangeState at deploy time) —
            // defaulting to 1 there is exactly correct, not just a
            // fallback: every bit predating the field really was ×1.
            $data['multiplierA'] ?? 1,
            $data['multiplierB'] ?? 1,
            $data['thrownMultiplier'] ?? 1,
        );
    }

    /**
     * Turns the coin over to its other face — the effect of an opponent's
     * "action" flip. Identifying "the other side" by (face, advantage,
     * multiplier) rather than just face value matters when both sides show
     * the same face type but differ in advantage and/or multiplier (e.g. an
     * "attack+advantage×2 / attack" bit) — comparing thrownFace alone would
     * be ambiguous there.
     */
    public function flipped(): self
    {
        $isCurrentlyA = $this->thrownFace === $this->faceA
            && $this->thrownAdvantage === $this->advantageA
            && $this->thrownMultiplier === $this->multiplierA;
        $newIsA = !$isCurrentlyA;

        return new self(
            $this->faceA,
            $this->faceB,
            $this->advantageA,
            $this->advantageB,
            $newIsA ? $this->faceA : $this->faceB,
            $newIsA ? $this->advantageA : $this->advantageB,
            $this->multiplierA,
            $this->multiplierB,
            $newIsA ? $this->multiplierA : $this->multiplierB,
        );
    }
}
