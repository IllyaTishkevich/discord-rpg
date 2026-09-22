<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * The result of throwing a single two-sided bit: its two possible faces and
 * which one is currently showing.
 */
final class BitThrow
{
    public function __construct(
        public readonly BitFace $faceA,
        public readonly BitFace $faceB,
        public readonly BitFace $thrownFace,
    ) {
    }

    public static function random(BitFace $faceA, BitFace $faceB): self
    {
        return new self($faceA, $faceB, random_int(0, 1) === 0 ? $faceA : $faceB);
    }

    /**
     * @return array{faceA: string, faceB: string, thrownFace: string}
     */
    public function toArray(): array
    {
        return [
            'faceA' => $this->faceA->value,
            'faceB' => $this->faceB->value,
            'thrownFace' => $this->thrownFace->value,
        ];
    }

    /**
     * @param array{faceA: string, faceB: string, thrownFace: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(BitFace::from($data['faceA']), BitFace::from($data['faceB']), BitFace::from($data['thrownFace']));
    }

    /**
     * Turns the coin over to its other face — the effect of an opponent's
     * "action" flip.
     */
    public function flipped(): self
    {
        $other = $this->thrownFace === $this->faceA ? $this->faceB : $this->faceA;

        return new self($this->faceA, $this->faceB, $other);
    }
}
