<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * One side's activation within a single exchange: a group of same-face
 * bits committed at once (docs/COMBAT_V2_DESIGN.md §3).
 */
final class Move
{
    public function __construct(
        public readonly BitFace $face,
        /** Literal number of bit objects activated — only used to mark the right amount of bits as spent. */
        public readonly int $count,
        /** Sum of those bits' multipliers — what actually drives damage/blocking/action-points/ability-cost. */
        public readonly int $amount,
    ) {
    }
}
