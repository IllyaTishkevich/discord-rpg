<?php

namespace App\Enum;

/**
 * What an Item actually does. Must match its ItemType's equippable/
 * consumable split (Item::validateEffectMatchesType()) — AddBit/AddAbility/
 * IncreaseCapacity/IncreaseMaxHp/IncreaseMaxEnergy only make sense on a worn
 * item (effect derived live from "is this equipped right now" — see
 * Character::getAllBits()/getAllAbilities()/getInventoryCapacity()/
 * getEffectiveMaxHp()/getEffectiveMaxEnergy()); Heal/RestoreEnergy/GrantXp
 * only make sense on a consumable, applied once by
 * InventoryService::useItem() and then the item is gone.
 */
enum ItemEffectType: string
{
    case AddBit = 'add_bit';
    case AddAbility = 'add_ability';
    case IncreaseCapacity = 'increase_capacity';
    case IncreaseMaxHp = 'increase_max_hp';
    case IncreaseMaxEnergy = 'increase_max_energy';
    case Heal = 'heal';
    case RestoreEnergy = 'restore_energy';
    case GrantXp = 'grant_xp';

    public function isEquippableEffect(): bool
    {
        return match ($this) {
            self::AddBit, self::AddAbility, self::IncreaseCapacity, self::IncreaseMaxHp, self::IncreaseMaxEnergy => true,
            self::Heal, self::RestoreEnergy, self::GrantXp => false,
        };
    }

    /**
     * Russian display label — admin panel and API responses.
     */
    public function label(): string
    {
        return match ($this) {
            self::AddBit => 'Добавляет биту',
            self::AddAbility => 'Добавляет способность',
            self::IncreaseCapacity => 'Увеличивает инвентарь',
            self::IncreaseMaxHp => 'Увеличивает макс. HP',
            self::IncreaseMaxEnergy => 'Увеличивает макс. энергию',
            self::Heal => 'Восполняет HP',
            self::RestoreEnergy => 'Восполняет энергию',
            self::GrantXp => 'Даёт опыт',
        };
    }
}
