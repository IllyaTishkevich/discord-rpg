<?php

namespace App\Exception;

/**
 * A fixed-cost ability (Reroll, DamageMirror) was chosen without enough
 * rolled action points to pay for it.
 */
class InsufficientActionPointsException extends \RuntimeException
{
}
