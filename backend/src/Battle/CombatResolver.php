<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * Resolves a single round: both sides throw their bits, spend any rolled
 * "action" faces to flip the opponent's already-thrown bits, then tally
 * attack vs. defense to compute damage. Net damage is
 * max(0, attackerAttackCount - defenderDefenseCount), mirroring the
 * simultaneous coin-reveal combat of Runebound 3rd ed.
 */
final class CombatResolver
{
    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     * @param int[]      $playerActionTargets indices into $opponentThrows the player chooses to flip;
     *                                        capped at however many "action" faces the player actually rolled
     */
    public function resolveRound(array $playerThrows, array $opponentThrows, array $playerActionTargets): RoundResult
    {
        $playerActionCount = $this->countThrownFace($playerThrows, BitFace::Action);
        $opponentActionCount = $this->countThrownFace($opponentThrows, BitFace::Action);

        $opponentThrows = $this->applyFlips($opponentThrows, \array_slice(array_values($playerActionTargets), 0, $playerActionCount));

        $botTargets = BotActionStrategy::chooseTargets(
            array_map(static fn (BitThrow $t) => $t->thrownFace, $playerThrows),
            $opponentActionCount,
        );
        $playerThrows = $this->applyFlips($playerThrows, $botTargets);

        $playerFaces = array_map(static fn (BitThrow $t) => $t->thrownFace, $playerThrows);
        $opponentFaces = array_map(static fn (BitThrow $t) => $t->thrownFace, $opponentThrows);

        $playerAttack = $this->countFace($playerFaces, BitFace::Attack);
        $playerDefense = $this->countFace($playerFaces, BitFace::Defense);
        $opponentAttack = $this->countFace($opponentFaces, BitFace::Attack);
        $opponentDefense = $this->countFace($opponentFaces, BitFace::Defense);

        return new RoundResult(
            playerFaces: $playerFaces,
            opponentFaces: $opponentFaces,
            damageToOpponent: max(0, $playerAttack - $opponentDefense),
            damageToPlayer: max(0, $opponentAttack - $playerDefense),
        );
    }

    /**
     * @param BitThrow[] $throws
     */
    private function countThrownFace(array $throws, BitFace $face): int
    {
        return \count(array_filter($throws, static fn (BitThrow $t) => $t->thrownFace === $face));
    }

    /**
     * @param BitFace[] $faces
     */
    private function countFace(array $faces, BitFace $face): int
    {
        return \count(array_filter($faces, static fn (BitFace $f) => $f === $face));
    }

    /**
     * @param BitThrow[] $throws
     * @param int[]      $targetIndices
     *
     * @return BitThrow[]
     */
    private function applyFlips(array $throws, array $targetIndices): array
    {
        foreach ($targetIndices as $index) {
            if (isset($throws[$index])) {
                $throws[$index] = $throws[$index]->flipped();
            }
        }

        return $throws;
    }
}
