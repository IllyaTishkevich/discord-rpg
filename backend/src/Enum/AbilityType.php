<?php

namespace App\Enum;

/**
 * What a side spends its rolled "action" faces on this round — one choice
 * for the whole round, not a mix (see docs/BATTLE_RULES.md). Flip is the
 * original/default behavior; the other three are the combat abilities.
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

    public function fixedCost(): ?int
    {
        return match ($this) {
            self::Reroll => 1,
            self::DamageMirror => 2,
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
        };
    }
}
