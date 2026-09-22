<?php

namespace App\Enum;

enum BattleStatus: string
{
    case InProgress = 'in_progress';
    case Won = 'won';
    case Lost = 'lost';
}
