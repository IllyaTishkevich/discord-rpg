<?php

namespace App\Exception;

/**
 * A PvP action was attempted against a battle that isn't in the right
 * state for it (e.g. submitting actions before both sides are ready, or
 * calling a PvP-only method on a PvE/event battle).
 */
class InvalidBattleStateException extends \RuntimeException
{
}
