<?php

namespace App\Battle;

use App\Enum\AbilityType;
use App\Enum\BitFace;

/**
 * Combat engine v2 (docs/COMBAT_V2_DESIGN.md): after both sides throw their
 * bits, whoever rolled more "advantage" faces leads the round's exchange
 * sequence. Each exchange: the leader activates a group of same-face bits,
 * the responder reacts (or passes), damage is resolved, and the lead
 * alternates — once a side runs out of un-activated bits it can no longer
 * lead or respond, so the other side just keeps leading solo (unopposed,
 * full damage each time) until it, too, runs out. Only then does the round
 * end and a fresh throw start the next one.
 *
 * Wired into non-interactive whole-round-at-once auto-play: PvE/event bot
 * text commands (BattleService::resolveRound()) and tournament simulation
 * (TournamentService). The interactive step-by-step flow used by the
 * Activity (PvE/event/PvP alike) uses InteractiveExchangeEngine instead —
 * the same rules, played out one exchange per request rather than all at
 * once.
 *
 * Not thread-safe / not reusable across calls — resolveRound() resets all
 * instance state at the top of each invocation.
 */
final class ExchangeResolver
{
    private \Closure $randomBool;

    /** @var BitThrow[] */
    private array $playerThrows;
    /** @var bool[] */
    private array $playerUsed;
    /** @var BitThrow[] */
    private array $opponentThrows;
    /** @var bool[] */
    private array $opponentUsed;

    private bool $playerMirrorActive;
    private bool $opponentMirrorActive;
    private bool $playerAbilityTriggered;
    private bool $opponentAbilityTriggered;

    /** @var Exchange[] */
    private array $exchanges;

    public function __construct(?\Closure $randomBool = null)
    {
        $this->randomBool = $randomBool ?? static fn (): bool => 1 === random_int(0, 1);
    }

    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    public function resolveRound(array $playerThrows, array $opponentThrows, AbilityChoice $playerChoice, AbilityChoice $opponentChoice): RoundResult
    {
        $this->playerThrows = array_values($playerThrows);
        $this->opponentThrows = array_values($opponentThrows);
        $this->playerUsed = array_fill(0, \count($this->playerThrows), false);
        $this->opponentUsed = array_fill(0, \count($this->opponentThrows), false);
        $this->playerMirrorActive = false;
        $this->opponentMirrorActive = false;
        $this->playerAbilityTriggered = false;
        $this->opponentAbilityTriggered = false;
        $this->exchanges = [];

        $damageToPlayerTotal = 0;
        $damageToOpponentTotal = 0;
        $leaderIsPlayer = $this->determineLeader();

        while ($this->remainingCount(true) > 0 || $this->remainingCount(false) > 0) {
            // The round doesn't end the moment one side runs out — whoever
            // still has bits keeps leading solo (the empty side can never
            // lead, and can't respond either, so it's full damage each
            // time — see docs/COMBAT_V2_DESIGN.md §3) until they, too, are
            // out. Only when BOTH are empty does the round actually end.
            if (0 === $this->remainingCount($leaderIsPlayer)) {
                $leaderIsPlayer = !$leaderIsPlayer;
            }

            $leaderChoice = $leaderIsPlayer ? $playerChoice : $opponentChoice;
            $responderChoice = $leaderIsPlayer ? $opponentChoice : $playerChoice;

            $leaderMove = $this->chooseLeadMove($leaderIsPlayer);
            $this->markUsed($leaderIsPlayer, $leaderMove->face, $leaderMove->count);
            $leaderBonus = BitFace::Action === $leaderMove->face
                ? $this->applyActionAbility($leaderIsPlayer, $leaderMove->count, $leaderChoice)
                : 0;

            $responderMove = $this->chooseResponseMove(!$leaderIsPlayer, $leaderMove);
            $responderBonus = 0;
            if (null !== $responderMove) {
                $this->markUsed(!$leaderIsPlayer, $responderMove->face, $responderMove->count);
                if (BitFace::Action === $responderMove->face) {
                    $responderBonus = $this->applyActionAbility(!$leaderIsPlayer, $responderMove->count, $responderChoice);
                }
            }

            $leaderAttack = BitFace::Attack === $leaderMove->face ? $leaderMove->count : 0;
            $leaderDefense = BitFace::Defense === $leaderMove->face ? $leaderMove->count : 0;
            $responderAttack = null !== $responderMove && BitFace::Attack === $responderMove->face ? $responderMove->count : 0;
            $responderDefense = null !== $responderMove && BitFace::Defense === $responderMove->face ? $responderMove->count : 0;

            $damageToResponderSide = max(0, $leaderAttack - $responderDefense) + $leaderBonus;
            $damageToLeaderSide = max(0, $responderAttack - $leaderDefense) + $responderBonus;

            [$exchangeDamageToPlayer, $exchangeDamageToOpponent] = $leaderIsPlayer
                ? [$damageToLeaderSide, $damageToResponderSide]
                : [$damageToResponderSide, $damageToLeaderSide];

            // Mirror uses a pre-mirror snapshot of both sides so simultaneous
            // DamageMirror on both sides can't feed back into itself.
            $baseDamageToPlayer = $exchangeDamageToPlayer;
            $baseDamageToOpponent = $exchangeDamageToOpponent;
            if ($this->playerMirrorActive && $baseDamageToPlayer > 0) {
                $exchangeDamageToOpponent += $baseDamageToPlayer;
            }
            if ($this->opponentMirrorActive && $baseDamageToOpponent > 0) {
                $exchangeDamageToPlayer += $baseDamageToOpponent;
            }

            $damageToPlayerTotal += $exchangeDamageToPlayer;
            $damageToOpponentTotal += $exchangeDamageToOpponent;

            $this->exchanges[] = new Exchange(
                $leaderIsPlayer,
                $leaderMove->face,
                $leaderMove->count,
                $responderMove?->face,
                $responderMove?->count ?? 0,
                $exchangeDamageToPlayer,
                $exchangeDamageToOpponent,
            );

            $leaderIsPlayer = !$leaderIsPlayer;
        }

        return new RoundResult(
            array_map(static fn (BitThrow $t) => $t->thrownFace, $this->playerThrows),
            array_map(static fn (BitThrow $t) => $t->thrownFace, $this->opponentThrows),
            $damageToOpponentTotal,
            $damageToPlayerTotal,
            $this->exchanges,
        );
    }

    private function determineLeader(): bool
    {
        $playerAdvantage = \count(array_filter($this->playerThrows, static fn (BitThrow $t) => $t->thrownAdvantage));
        $opponentAdvantage = \count(array_filter($this->opponentThrows, static fn (BitThrow $t) => $t->thrownAdvantage));

        if ($playerAdvantage === $opponentAdvantage) {
            return ($this->randomBool)();
        }

        return $playerAdvantage > $opponentAdvantage;
    }

    /**
     * Heuristic default (docs/COMBAT_V2_DESIGN.md §6) — offense first, then
     * bank action points, defense last since it does nothing without an
     * incoming attack to block. Subject to tuning once real playtesting
     * data exists.
     */
    private function chooseLeadMove(bool $isPlayerSide): Move
    {
        foreach ([BitFace::Attack, BitFace::Action, BitFace::Defense] as $face) {
            $count = $this->remainingCountByFace($isPlayerSide, $face);
            if ($count > 0) {
                return new Move($face, $count);
            }
        }

        throw new \LogicException('chooseLeadMove called with no remaining bits.');
    }

    private function chooseResponseMove(bool $isPlayerSide, Move $incoming): ?Move
    {
        if (BitFace::Attack === $incoming->face) {
            $defenseCount = $this->remainingCountByFace($isPlayerSide, BitFace::Defense);
            if ($defenseCount > 0) {
                // Only commit as much defense as actually needed to block —
                // no reason to burn a whole reserve blocking one attack.
                return new Move(BitFace::Defense, min($defenseCount, $incoming->count));
            }
        }

        if (0 === $this->remainingCount($isPlayerSide)) {
            return null;
        }

        return $this->chooseLeadMove($isPlayerSide);
    }

    /**
     * @return int bonus unblockable damage to apply to the OTHER side (0
     *             for Flip/Reroll/DamageMirror, whose effects aren't direct damage)
     */
    private function applyActionAbility(bool $isActingSidePlayer, int $count, AbilityChoice $choice): int
    {
        $alreadyTriggered = $isActingSidePlayer ? $this->playerAbilityTriggered : $this->opponentAbilityTriggered;

        if ($alreadyTriggered || AbilityType::Flip === $choice->ability) {
            return $this->applyFlip($isActingSidePlayer, $count, $choice->targets);
        }

        $cost = $choice->ability->fixedCost() ?? $count;
        if ($count < $cost) {
            // Not enough banked in this single move to actually afford it —
            // a later move this round may have enough; for now just flip.
            return $this->applyFlip($isActingSidePlayer, $count, $choice->targets);
        }

        if ($isActingSidePlayer) {
            $this->playerAbilityTriggered = true;
        } else {
            $this->opponentAbilityTriggered = true;
        }

        $bonus = match ($choice->ability) {
            AbilityType::UnblockableDamage => $cost,
            AbilityType::Reroll => $this->applyReroll($isActingSidePlayer),
            AbilityType::DamageMirror => $this->activateMirror($isActingSidePlayer),
            AbilityType::Flip => 0, // unreachable — handled above
        };

        // Any action points activated in this same move beyond the
        // ability's fixed cost (e.g. DamageMirror costs 2 but 3 were
        // activated at once) fall back to a Flip on the remainder.
        $leftover = $count - $cost;
        if ($leftover > 0) {
            $bonus += $this->applyFlip($isActingSidePlayer, $leftover, $choice->targets);
        }

        return $bonus;
    }

    private function applyFlip(bool $isActingSidePlayer, int $count, array $declaredTargets): int
    {
        if ($count <= 0) {
            return 0;
        }

        $targetThrows = $isActingSidePlayer ? $this->opponentThrows : $this->playerThrows;
        $targetUsed = $isActingSidePlayer ? $this->opponentUsed : $this->playerUsed;

        foreach ($this->chooseFlipTargets($declaredTargets, $targetThrows, $targetUsed, $count) as $i) {
            if ($isActingSidePlayer) {
                $this->opponentThrows[$i] = $this->opponentThrows[$i]->flipped();
            } else {
                $this->playerThrows[$i] = $this->playerThrows[$i]->flipped();
            }
        }

        return 0;
    }

    /**
     * @param int[]      $declaredTargets indices the player explicitly asked to flip
     * @param BitThrow[] $throws
     * @param bool[]     $used
     *
     * @return int[]
     */
    private function chooseFlipTargets(array $declaredTargets, array $throws, array $used, int $maxCount): array
    {
        $chosen = [];
        foreach ($declaredTargets as $i) {
            if (\count($chosen) >= $maxCount) {
                break;
            }
            if (isset($throws[$i]) && !($used[$i] ?? true) && !\in_array($i, $chosen, true)) {
                $chosen[] = $i;
            }
        }

        if (\count($chosen) < $maxCount) {
            foreach ([BitFace::Attack, BitFace::Defense] as $face) {
                foreach ($throws as $i => $throw) {
                    if (\count($chosen) >= $maxCount) {
                        break 2;
                    }
                    if (($used[$i] ?? true) || \in_array($i, $chosen, true)) {
                        continue;
                    }
                    if ($throw->thrownFace === $face) {
                        $chosen[] = $i;
                    }
                }
            }
        }

        return $chosen;
    }

    /**
     * Rerolls every other still-unactivated bit of this side. The bit(s)
     * just spent to pay for Reroll are excluded (already marked used by the
     * time this runs) — their post-reroll face would never be read again
     * anyway, so this is a harmless simplification of "включая только что
     * потраченный" (see docs/COMBAT_V2_DESIGN.md §5).
     */
    private function applyReroll(bool $isActingSidePlayer): int
    {
        if ($isActingSidePlayer) {
            foreach ($this->playerThrows as $i => $throw) {
                if (!$this->playerUsed[$i]) {
                    $this->playerThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB);
                }
            }
        } else {
            foreach ($this->opponentThrows as $i => $throw) {
                if (!$this->opponentUsed[$i]) {
                    $this->opponentThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB);
                }
            }
        }

        return 0;
    }

    private function activateMirror(bool $isActingSidePlayer): int
    {
        if ($isActingSidePlayer) {
            $this->playerMirrorActive = true;
        } else {
            $this->opponentMirrorActive = true;
        }

        return 0;
    }

    private function markUsed(bool $isPlayerSide, BitFace $face, int $count): void
    {
        $throws = $isPlayerSide ? $this->playerThrows : $this->opponentThrows;
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        $marked = 0;
        foreach ($throws as $i => $throw) {
            if ($marked >= $count) {
                break;
            }
            if (!$used[$i] && $throw->thrownFace === $face) {
                $used[$i] = true;
                ++$marked;
            }
        }

        if ($isPlayerSide) {
            $this->playerUsed = $used;
        } else {
            $this->opponentUsed = $used;
        }
    }

    private function remainingCountByFace(bool $isPlayerSide, BitFace $face): int
    {
        $throws = $isPlayerSide ? $this->playerThrows : $this->opponentThrows;
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        $count = 0;
        foreach ($throws as $i => $throw) {
            if (!$used[$i] && $throw->thrownFace === $face) {
                ++$count;
            }
        }

        return $count;
    }

    private function remainingCount(bool $isPlayerSide): int
    {
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        return \count(array_filter($used, static fn (bool $u) => !$u));
    }
}
