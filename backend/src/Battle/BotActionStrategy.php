<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * Simple heuristic the bot opponent uses to spend the action points it
 * rolled: prioritize survival (flip the player's "attack" faces first to
 * reduce incoming damage), then spend any remainder on offense (flip the
 * player's "defense" faces to increase the bot's own outgoing damage).
 *
 * Subject to tuning once real playtesting data exists (see docs/ROADMAP.md,
 * "Балансировка после первых тестовых боёв").
 */
final class BotActionStrategy
{
    /**
     * @param BitFace[] $playerThrownFaces
     *
     * @return int[] indices into $playerThrownFaces to flip
     */
    public static function chooseTargets(array $playerThrownFaces, int $actionCount): array
    {
        if ($actionCount <= 0) {
            return [];
        }

        $targets = [];

        foreach ([BitFace::Attack, BitFace::Defense] as $priorityFace) {
            foreach ($playerThrownFaces as $index => $face) {
                if (\count($targets) >= $actionCount) {
                    return $targets;
                }
                if ($face === $priorityFace && !\in_array($index, $targets, true)) {
                    $targets[] = $index;
                }
            }
        }

        return $targets;
    }
}
