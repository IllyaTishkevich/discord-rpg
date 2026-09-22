<?php

namespace App\Enum;

enum BattleStatus: string
{
    // PvP only: challenge issued, waiting on accept + both sides to ready up
    // in the Activity before the first round can be thrown.
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Won = 'won';
    case Lost = 'lost';
    // PvP only: declined, or one side never readied up.
    case Abandoned = 'abandoned';
}
