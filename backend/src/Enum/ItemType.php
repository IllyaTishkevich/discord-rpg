<?php

namespace App\Enum;

/**
 * What kind of loot item this is — drives whether it's equipped (permanent
 * effect while worn) or consumed (instant effect, then destroyed). See
 * ItemEffectType for the matching effect-kind split.
 */
enum ItemType: string
{
    case Weapon = 'weapon';
    case Shield = 'shield';
    case Armor = 'armor';
    case Bag = 'bag';
    case Potion = 'potion';
    case Scroll = 'scroll';

    /**
     * Weapon/Shield/Armor/Bag — worn via InventoryService::useItem(), stays
     * in the inventory, effect active only while equipped==true. Only one
     * item of a given type can be equipped at once (auto-swaps the previous
     * one — see InventoryService).
     */
    public function isEquippable(): bool
    {
        return match ($this) {
            self::Weapon, self::Shield, self::Armor, self::Bag => true,
            self::Potion, self::Scroll => false,
        };
    }

    /**
     * Potion/Scroll — effect applies immediately on use, then the item is
     * removed from the inventory for good.
     */
    public function isConsumable(): bool
    {
        return !$this->isEquippable();
    }

    /**
     * Russian display label — admin panel and API responses.
     */
    public function label(): string
    {
        return match ($this) {
            self::Weapon => 'Оружие',
            self::Shield => 'Щит',
            self::Armor => 'Доспех',
            self::Bag => 'Сумка',
            self::Potion => 'Зелье',
            self::Scroll => 'Свиток',
        };
    }
}
