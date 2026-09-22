<?php

namespace App\Enum;

enum EquipmentEffectType: string
{
    // Permanently grants the character a new Bit (faceA/faceB on the item).
    case Bit = 'bit';
    // Permanently increases the character's max HP by hpBonus.
    case Hp = 'hp';
}
