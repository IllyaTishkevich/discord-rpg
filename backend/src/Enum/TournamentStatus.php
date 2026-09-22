<?php

namespace App\Enum;

enum TournamentStatus: string
{
    case Registration = 'registration';
    case Finished = 'finished';
}
