<?php

namespace App\Enum;

/**
 * What a side spends its rolled "action" faces on this round — one choice
 * for the whole round, not a mix (see docs/BATTLE_RULES.md). Flip is the
 * original/default behavior; the other five are the combat abilities.
 */
enum AbilityType: string
{
    // Spend N action points to flip N of the opponent's already-thrown bits.
    case Flip = 'flip';
    // Spend N action points to deal N unblockable damage (added after the
    // normal attack-vs-defense tally, ignores defense entirely).
    case UnblockableDamage = 'unblockable_damage';
    // Spend 1 action point to re-throw all of your own bits (including the
    // one that paid for this), discarding this round's original throw.
    case Reroll = 'reroll';
    // Spend 2 action points: until the end of this round, the opponent also
    // takes whatever damage you take this round.
    case DamageMirror = 'damage_mirror';
    // Spend 2 action points to permanently destroy one of the opponent's
    // not-yet-activated bits — it's gone for the rest of the round, not
    // just flipped to a different face.
    case Destroy = 'destroy';
    // Spend 2 action points to permanently double the multiplier of one of
    // your OWN not-yet-activated bits (the currently-showing face only —
    // see BitThrow::doubled()). Targets your own throw, not the opponent's.
    case Double = 'double';

    public function fixedCost(): ?int
    {
        return match ($this) {
            self::Reroll => 1,
            self::DamageMirror, self::Destroy, self::Double => 2,
            self::Flip, self::UnblockableDamage => null, // variable — capped by/equal to rolled action points
        };
    }

    /**
     * Russian display label — admin panel only (see Ability::__toString()).
     */
    public function label(): string
    {
        return match ($this) {
            self::Flip => 'Переворот',
            self::UnblockableDamage => 'Неблокируемый урон',
            self::Reroll => 'Переброс',
            self::DamageMirror => 'Зеркало урона',
            self::Destroy => 'Уничтожение',
            self::Double => 'Удвоение',
        };
    }
}
