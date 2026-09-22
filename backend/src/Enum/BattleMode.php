<?php

namespace App\Enum;

enum BattleMode: string
{
    case Pve = 'pve';
    case Event = 'event';
    case Pvp = 'pvp';
}
