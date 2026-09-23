<?php

namespace App\Battle;

use App\Enum\AbilityType;
use App\Enum\BitFace;
use App\Exception\InsufficientActionPointsException;

/**
 * Applies the effect of a chosen combat ability. `assertAffordable()` and
 * `countActionFaces()` are still used by the non-interactive whole-round
 * path (BattleService::resolveRound(), via ExchangeResolver). The other
 * three methods (applyPreDamage/effectiveFlipTargets/applyPostDamage) were
 * written for the old simultaneous-reveal CombatResolver — Reroll transformed
 * the throw *before* it ran, UnblockableDamage/DamageMirror adjusted its
 * result *after* — and are unused now that CombatResolver/PvP's old
 * whole-round flow are gone (docs/COMBAT_V2_DESIGN.md §7); kept because
 * they're still correct standalone logic with their own test coverage
 * (AbilityResolverTest), not because anything currently calls them.
 *
 * See docs/BATTLE_RULES.md for the rules and docs/BATTLE_ROOM_DESIGN.md's
 * successor discussion for why abilities are "pick one, spend everything
 * rolled on it" rather than a mix in the same round.
 */
final class AbilityResolver
{
    public function assertAffordable(AbilityChoice $choice, int $rolledActionCount): void
    {
        $cost = $choice->ability->fixedCost();
        if (null !== $cost && $rolledActionCount < $cost) {
            throw new InsufficientActionPointsException(sprintf(
                '%s requires %d action point(s), but only %d were rolled.',
                $choice->ability->value,
                $cost,
                $rolledActionCount,
            ));
        }
    }

    /**
     * @param BitThrow[] $throws the side's own bits, as thrown this round
     *
     * @return BitThrow[]
     */
    public function applyPreDamage(array $throws, AbilityChoice $choice): array
    {
        if (AbilityType::Reroll !== $choice->ability) {
            return $throws;
        }

        return array_map(static fn (BitThrow $t) => BitThrow::random($t->faceA, $t->faceB, multiplierA: $t->multiplierA, multiplierB: $t->multiplierB), $throws);
    }

    /**
     * @return int[] flip target indices — empty unless the chosen ability
     *               is actually Flip
     */
    public function effectiveFlipTargets(AbilityChoice $choice): array
    {
        return AbilityType::Flip === $choice->ability ? $choice->targets : [];
    }

    public function applyPostDamage(RoundResult $result, AbilityChoice $characterChoice, AbilityChoice $opponentChoice, int $characterActionCount, int $opponentActionCount): RoundResult
    {
        $damageToOpponent = $result->damageToOpponent;
        $damageToPlayer = $result->damageToPlayer;

        // Snapshot the pre-mirror values: if both sides mirror in the same
        // round, each side's mirror must react to what the *other* side
        // took before any mirroring, not a value already inflated by the
        // other side's own mirror (which would feed back on itself).
        $baseDamageToOpponent = $damageToOpponent;
        $baseDamageToPlayer = $damageToPlayer;

        if (AbilityType::UnblockableDamage === $characterChoice->ability) {
            $damageToOpponent += $characterActionCount;
        }
        if (AbilityType::UnblockableDamage === $opponentChoice->ability) {
            $damageToPlayer += $opponentActionCount;
        }
        if (AbilityType::DamageMirror === $characterChoice->ability) {
            $damageToOpponent += $baseDamageToPlayer;
        }
        if (AbilityType::DamageMirror === $opponentChoice->ability) {
            $damageToPlayer += $baseDamageToOpponent;
        }

        return new RoundResult($result->playerFaces, $result->opponentFaces, $damageToOpponent, $damageToPlayer);
    }

    /**
     * @param BitThrow[] $throws
     */
    public function countActionFaces(array $throws): int
    {
        return \count(array_filter($throws, static fn (BitThrow $t) => BitFace::Action === $t->thrownFace));
    }
}
