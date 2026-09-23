<?php

namespace App\Enum;

enum BitFace: string
{
    case Attack = 'attack';
    case Defense = 'defense';
    case Action = 'action';
    // Never activates — a bit showing this face takes no part in the round
    // at all: it can't be led or responded with, doesn't count toward
    // advantage or "bits remaining" (docs/COMBAT_V2_DESIGN.md §1/§3), and is
    // excluded exactly like an already-used bit everywhere except one: Flip
    // can still target it (an explicitly *declared* target only — the
    // auto-fallback target picker skips it same as Action), turning it over
    // to reveal its other, real face and bringing it back into play.
    case Empty = 'empty';
}
