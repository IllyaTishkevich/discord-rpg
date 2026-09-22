<?php

namespace App\Exception;

/**
 * Base for tournament registration/start errors; controllers catch the
 * specific subclass to pick the right HTTP status.
 */
abstract class TournamentException extends \RuntimeException
{
}
