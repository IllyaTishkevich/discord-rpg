<?php

namespace App\Exception;

/**
 * A character that is neither `battle.character` nor `battle.opponentCharacter`
 * tried to act on a PvP battle.
 */
class NotBattleParticipantException extends \RuntimeException
{
}
