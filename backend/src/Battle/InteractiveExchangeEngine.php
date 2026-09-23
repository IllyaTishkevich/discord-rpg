<?php

namespace App\Battle;

use App\Enum\AbilityType;
use App\Enum\BitFace;
use App\Exception\InvalidExchangeMoveException;

/**
 * Step-by-step version of the combat v2 exchange sequence
 * (docs/COMBAT_V2_DESIGN.md §7-8) — unlike ExchangeResolver (which plays a
 * whole round out instantly with a heuristic driving both sides), this one
 * pauses whenever it's the real player's turn to act and lets the bot's
 * side auto-play via the same kind of heuristic. State is external
 * (ExchangeRoundState), persisted on Battle between requests since a round
 * can span several player decisions.
 *
 * Call pattern (see BattleService::submitExchangeMove()):
 *  - startRound() right after a throw — internally auto-plays the bot's
 *    opening move(s) if it leads, so the returned state is already settled
 *    to a real player decision point (or 'over', in the degenerate case of
 *    a zero-bit hand).
 *  - submitLead()/submitRespond() apply the player's decision and return
 *    the one exchange it resolved (the bot side of a PvE exchange never
 *    needs a separate request, since there are only two sides).
 *  - After any of those, call autoAdvance() in a loop — each call either
 *    auto-plays one more bot-driven exchange (when the player has nothing
 *    left to respond with) or returns a null exchange once it's genuinely
 *    the player's turn again / the round has ended. The caller applies HP
 *    and checks for an early knockout after *each* returned exchange
 *    (from startRound, submitLead/Respond, or autoAdvance alike), stopping
 *    before driving the loop further if the battle just ended.
 *  - currentTurn() is only meaningful once that loop has been driven to a
 *    null exchange — it can't distinguish "your turn to lead" from "the
 *    bot still has an unplayed lead move" otherwise.
 */
final class InteractiveExchangeEngine
{
    private \Closure $randomBool;

    public function __construct(?\Closure $randomBool = null)
    {
        $this->randomBool = $randomBool ?? static fn (): bool => 1 === random_int(0, 1);
    }

    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     *
     * @return array{state: ExchangeRoundState, exchanges: array[]} the (possibly already-advanced)
     *               state plus any bot-solo exchanges resolved before the first real
     *               decision point — only possible in the degenerate case where the
     *               player rolled zero bits (see currentTurn())
     */
    public function startRound(array $playerThrows, array $opponentThrows, AbilityChoice $botChoice = new AbilityChoice(AbilityType::Flip)): array
    {
        $state = $this->buildInitialState($playerThrows, $opponentThrows);

        // If the bot leads first, its move (and any immediately-following
        // bot-solo exchanges, in the degenerate all-bot-bits case) must be
        // played out now — otherwise currentTurn() would wrongly report
        // 'lead' before anyone has actually led.
        $exchanges = [];
        while (!$state->leaderIsPlayer && !$state->isOver() && null === $state->pendingLeaderMove) {
            ['state' => $state, 'exchange' => $exchange] = $this->autoAdvance($state, $botChoice);
            if (null === $exchange) {
                break;
            }
            $exchanges[] = $exchange;
        }

        return ['state' => $state, 'exchanges' => $exchanges];
    }

    /**
     * PvP-only entry point (docs/COMBAT_V2_DESIGN.md §7): both sides are
     * real players, so — unlike startRound() — nobody auto-plays here even
     * if the coin flip favors the opponent. Just determines who leads first.
     *
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    public function startPvpRound(array $playerThrows, array $opponentThrows): ExchangeRoundState
    {
        return $this->buildInitialState($playerThrows, $opponentThrows);
    }

    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    private function buildInitialState(array $playerThrows, array $opponentThrows): ExchangeRoundState
    {
        $playerThrows = array_values($playerThrows);
        $opponentThrows = array_values($opponentThrows);

        $playerAdvantage = \count(array_filter($playerThrows, static fn (BitThrow $t) => $t->thrownAdvantage));
        $opponentAdvantage = \count(array_filter($opponentThrows, static fn (BitThrow $t) => $t->thrownAdvantage));
        $leaderIsPlayer = $playerAdvantage === $opponentAdvantage
            ? ($this->randomBool)()
            : $playerAdvantage > $opponentAdvantage;

        return new ExchangeRoundState(
            $playerThrows,
            $opponentThrows,
            array_fill(0, \count($playerThrows), false),
            array_fill(0, \count($opponentThrows), false),
            $leaderIsPlayer,
            null,
            false,
            false,
            false,
            false,
            [],
        );
    }

    /**
     * Only meaningful once the caller has driven autoAdvance() to a null
     * exchange (see the class docblock) — until then, the bot may still
     * have an unplayed lead move pending, which this can't distinguish
     * from "genuinely your turn to lead".
     *
     * @return 'lead'|'respond'|'over'
     */
    public function currentTurn(ExchangeRoundState $state): string
    {
        if ($state->isOver()) {
            return 'over';
        }

        return null !== $state->pendingLeaderMove ? 'respond' : 'lead';
    }

    /**
     * PvP-only (docs/COMBAT_V2_DESIGN.md §7): unlike currentTurn() (always
     * implicitly "the player"'s status, meaningful only after auto-advancing
     * past any bot turns), this reports *this specific side's* status
     * directly — 'wait' when the other real side currently owns the
     * decision. Safe to call at any time; doesn't assume either side has
     * already acted.
     *
     * @return 'lead'|'respond'|'wait'|'over'
     */
    public function turnForSide(ExchangeRoundState $state, bool $isPlayerSide): string
    {
        if ($state->isOver()) {
            return 'over';
        }

        if (null !== $state->pendingLeaderMove) {
            $responderIsPlayer = !$state->leaderIsPlayer;

            return $isPlayerSide === $responderIsPlayer ? 'respond' : 'wait';
        }

        return $isPlayerSide === $this->effectiveLeaderIsPlayer($state) ? 'lead' : 'wait';
    }

    /**
     * $state->leaderIsPlayer can point at a side that's since run out of
     * bits (e.g. after the other side spent its last few on a lead move) —
     * this is the side that must *actually* lead next, rerouting to
     * whoever still has bits (docs/COMBAT_V2_DESIGN.md §3's "continue
     * solo" rule). Mirrors the reroute check at the top of autoAdvance(),
     * but as a pure read — callers that actually commit to this leader
     * (submitPvpLead()) still need to persist it onto $state themselves.
     */
    private function effectiveLeaderIsPlayer(ExchangeRoundState $state): bool
    {
        return $state->remainingCount($state->leaderIsPlayer) > 0
            ? $state->leaderIsPlayer
            : !$state->leaderIsPlayer;
    }

    /**
     * PvP-only: the given side leads with the given bits. Unlike
     * submitLead() (PvE, where the bot's response is computed synchronously
     * in the same call), this only commits the lead move and pauses —
     * the actual second player must call submitPvpRespond() separately.
     *
     * @param int[] $indices
     */
    public function submitPvpLead(ExchangeRoundState $state, bool $isPlayerSide, array $indices, ?AbilityChoice $ability): ExchangeRoundState
    {
        if ('lead' !== $this->turnForSide($state, $isPlayerSide)) {
            throw new InvalidExchangeMoveException('It is not your turn to lead this exchange.');
        }

        $state->leaderIsPlayer = $isPlayerSide;
        $face = $this->validateAndConsumeMove($state, $isPlayerSide, $indices);
        $count = \count($indices);
        $bonus = BitFace::Action === $face
            ? $this->applyActionAbility($state, $isPlayerSide, $count, $ability ?? AbilityChoice::flip())
            : 0;

        $state->pendingLeaderMove = ['face' => $face->value, 'count' => $count, 'bonus' => $bonus];

        return $state;
    }

    /**
     * PvP-only: the given side declines to lead this exchange (e.g. its
     * move-deadline expired — see BattleService) — initiative passes to
     * the other side without consuming any bits or resolving anything.
     */
    public function passPvpLead(ExchangeRoundState $state, bool $isPlayerSide): ExchangeRoundState
    {
        if ('lead' !== $this->turnForSide($state, $isPlayerSide)) {
            throw new InvalidExchangeMoveException('It is not your turn to lead this exchange.');
        }

        $state->leaderIsPlayer = !$isPlayerSide;

        return $state;
    }

    /**
     * PvP-only: the given side responds to the other side's already-pending
     * lead move (or passes, with an empty $indices — full damage from the
     * incoming move, per docs/COMBAT_V2_DESIGN.md §4).
     *
     * @param int[] $indices
     *
     * @return array{state: ExchangeRoundState, exchange: array}
     */
    public function submitPvpRespond(ExchangeRoundState $state, bool $isPlayerSide, array $indices, ?AbilityChoice $ability): array
    {
        if ('respond' !== $this->turnForSide($state, $isPlayerSide)) {
            throw new InvalidExchangeMoveException('You are not being asked to respond right now.');
        }

        $leaderMove = $state->pendingLeaderMove;
        $leaderFace = BitFace::from($leaderMove['face']);
        $leaderCount = $leaderMove['count'];
        $leaderBonus = $leaderMove['bonus'];

        $responderFace = null;
        $responderCount = 0;
        $responderBonus = 0;
        if ([] !== $indices) {
            $responderFace = $this->validateAndConsumeMove($state, $isPlayerSide, $indices);
            $responderCount = \count($indices);
            if (BitFace::Action === $responderFace) {
                $responderBonus = $this->applyActionAbility($state, $isPlayerSide, $responderCount, $ability ?? AbilityChoice::flip());
            }
        }

        $responderMove = null === $responderFace ? null : ['face' => $responderFace, 'count' => $responderCount];
        $leaderIsPlayerForExchange = !$isPlayerSide;
        $exchange = $this->resolveExchange($state, $leaderIsPlayerForExchange, $leaderFace, $leaderCount, $leaderBonus, $responderMove, $responderBonus);
        $state->pendingLeaderMove = null;
        $state->leaderIsPlayer = $isPlayerSide;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * @return array{face: string, count: int}|null the bot's already-committed move, if it's the player's turn to respond
     */
    public function getIncomingMove(ExchangeRoundState $state): ?array
    {
        if (null === $state->pendingLeaderMove) {
            return null;
        }

        return ['face' => $state->pendingLeaderMove['face'], 'count' => $state->pendingLeaderMove['count']];
    }

    /**
     * The player leads with the given bits (must all show the same face,
     * belong to the player, and be currently unused). Since the opponent in
     * PvE/event is always the bot, its response is computed and the
     * exchange fully resolved in this same call — no separate request
     * needed for "the bot's turn to respond".
     *
     * @param int[] $indices
     *
     * @return array{state: ExchangeRoundState, exchange: array}
     */
    public function submitLead(ExchangeRoundState $state, array $indices, ?AbilityChoice $ability, AbilityChoice $botChoice): array
    {
        if ('lead' !== $this->currentTurn($state)) {
            throw new InvalidExchangeMoveException('It is not your turn to lead this exchange.');
        }

        $face = $this->validateAndConsumeMove($state, true, $indices);
        $count = \count($indices);
        $leaderBonus = BitFace::Action === $face
            ? $this->applyActionAbility($state, true, $count, $ability ?? AbilityChoice::flip())
            : 0;

        $responderMove = $this->chooseResponseMove($state, false, $face, $count);
        $responderBonus = 0;
        if (null !== $responderMove) {
            $this->markUsed($state, false, $responderMove['face'], $responderMove['count']);
            if (BitFace::Action === $responderMove['face']) {
                $responderBonus = $this->applyActionAbility($state, false, $responderMove['count'], $botChoice);
            }
        }

        $exchange = $this->resolveExchange($state, true, $face, $count, $leaderBonus, $responderMove, $responderBonus);
        $state->leaderIsPlayer = false;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * The player responds to the bot's already-committed lead move. An
     * empty $indices array means passing (no response — full damage from
     * the incoming move, per docs/COMBAT_V2_DESIGN.md §4).
     *
     * @param int[] $indices
     *
     * @return array{state: ExchangeRoundState, exchange: array}
     */
    public function submitRespond(ExchangeRoundState $state, array $indices, ?AbilityChoice $ability): array
    {
        if ('respond' !== $this->currentTurn($state)) {
            throw new InvalidExchangeMoveException('You are not being asked to respond right now.');
        }

        $leaderMove = $state->pendingLeaderMove;
        $leaderFace = BitFace::from($leaderMove['face']);
        $leaderCount = $leaderMove['count'];
        $leaderBonus = $leaderMove['bonus'];

        $responderFace = null;
        $responderCount = 0;
        $responderBonus = 0;
        if (\count($indices) > 0) {
            $responderFace = $this->validateAndConsumeMove($state, true, $indices);
            $responderCount = \count($indices);
            if (BitFace::Action === $responderFace) {
                $responderBonus = $this->applyActionAbility($state, true, $responderCount, $ability ?? AbilityChoice::flip());
            }
        }

        $responderMove = null === $responderFace ? null : ['face' => $responderFace, 'count' => $responderCount];
        $exchange = $this->resolveExchange($state, false, $leaderFace, $leaderCount, $leaderBonus, $responderMove, $responderBonus);
        $state->pendingLeaderMove = null;
        $state->leaderIsPlayer = true;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * Plays exactly one bot-driven step if one is currently possible:
     * either the bot leading (pausing for the player's response if it has
     * one, or resolving immediately if the player has nothing left to
     * respond with), or nothing at all if it's genuinely the player's turn
     * or the round already ended.
     *
     * @return array{state: ExchangeRoundState, exchange: array|null}
     */
    public function autoAdvance(ExchangeRoundState $state, AbilityChoice $botChoice): array
    {
        if ($state->isOver()) {
            return ['state' => $state, 'exchange' => null];
        }

        if (0 === $state->remainingCount($state->leaderIsPlayer)) {
            $state->leaderIsPlayer = !$state->leaderIsPlayer;
        }
        if (0 === $state->remainingCount($state->leaderIsPlayer)) {
            return ['state' => $state, 'exchange' => null];
        }

        if ($state->leaderIsPlayer) {
            // Genuinely the player's turn to lead — nothing to auto-play.
            return ['state' => $state, 'exchange' => null];
        }

        $leaderMove = $this->chooseLeadMove($state, false);
        $this->markUsed($state, false, $leaderMove['face'], $leaderMove['count']);
        $leaderBonus = BitFace::Action === $leaderMove['face']
            ? $this->applyActionAbility($state, false, $leaderMove['count'], $botChoice)
            : 0;

        if ($state->remainingCount(true) > 0) {
            // Player can respond — pause here and let the caller ask them.
            $state->pendingLeaderMove = [
                'face' => $leaderMove['face']->value,
                'count' => $leaderMove['count'],
                'bonus' => $leaderBonus,
            ];

            return ['state' => $state, 'exchange' => null];
        }

        // Player has nothing left to respond with — resolve unopposed.
        $exchange = $this->resolveExchange($state, false, $leaderMove['face'], $leaderMove['count'], $leaderBonus, null, 0);
        $state->leaderIsPlayer = true;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * @param int[] $indices
     */
    private function validateAndConsumeMove(ExchangeRoundState $state, bool $isPlayerSide, array $indices): BitFace
    {
        if ([] === $indices) {
            throw new InvalidExchangeMoveException('At least one bit must be selected to lead or respond.');
        }

        $throws = $isPlayerSide ? $state->playerThrows : $state->opponentThrows;
        $used = $isPlayerSide ? $state->playerUsed : $state->opponentUsed;

        $face = null;
        foreach (array_unique($indices) as $index) {
            if (!isset($throws[$index]) || ($used[$index] ?? true)) {
                throw new InvalidExchangeMoveException(\sprintf('Bit index %d is invalid or already used.', $index));
            }
            if (null === $face) {
                $face = $throws[$index]->thrownFace;
            } elseif ($face !== $throws[$index]->thrownFace) {
                throw new InvalidExchangeMoveException('All selected bits must show the same face.');
            }
        }

        $this->markUsed($state, $isPlayerSide, $face, \count(array_unique($indices)));

        return $face;
    }

    private function chooseLeadMove(ExchangeRoundState $state, bool $isPlayerSide): array
    {
        foreach ([BitFace::Attack, BitFace::Action, BitFace::Defense] as $face) {
            $count = $state->remainingCountByFace($isPlayerSide, $face);
            if ($count > 0) {
                return ['face' => $face, 'count' => $count];
            }
        }

        throw new \LogicException('chooseLeadMove called with no remaining bits.');
    }

    /**
     * @return array{face: BitFace, count: int}|null
     */
    private function chooseResponseMove(ExchangeRoundState $state, bool $isPlayerSide, BitFace $incomingFace, int $incomingCount): ?array
    {
        if (BitFace::Attack === $incomingFace) {
            $defenseCount = $state->remainingCountByFace($isPlayerSide, BitFace::Defense);
            if ($defenseCount > 0) {
                return ['face' => BitFace::Defense, 'count' => min($defenseCount, $incomingCount)];
            }
        }

        if (0 === $state->remainingCount($isPlayerSide)) {
            return null;
        }

        return $this->chooseLeadMove($state, $isPlayerSide);
    }

    /**
     * @param array{face: BitFace, count: int}|null $responderMove
     *
     * @return array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}
     */
    private function resolveExchange(
        ExchangeRoundState $state,
        bool $leaderIsPlayer,
        BitFace $leaderFace,
        int $leaderCount,
        int $leaderBonus,
        ?array $responderMove,
        int $responderBonus,
    ): array {
        $leaderAttack = BitFace::Attack === $leaderFace ? $leaderCount : 0;
        $leaderDefense = BitFace::Defense === $leaderFace ? $leaderCount : 0;
        $responderAttack = null !== $responderMove && BitFace::Attack === $responderMove['face'] ? $responderMove['count'] : 0;
        $responderDefense = null !== $responderMove && BitFace::Defense === $responderMove['face'] ? $responderMove['count'] : 0;

        $damageToResponderSide = max(0, $leaderAttack - $responderDefense) + $leaderBonus;
        $damageToLeaderSide = max(0, $responderAttack - $leaderDefense) + $responderBonus;

        [$exchangeDamageToPlayer, $exchangeDamageToOpponent] = $leaderIsPlayer
            ? [$damageToLeaderSide, $damageToResponderSide]
            : [$damageToResponderSide, $damageToLeaderSide];

        $baseDamageToPlayer = $exchangeDamageToPlayer;
        $baseDamageToOpponent = $exchangeDamageToOpponent;
        if ($state->playerMirrorActive && $baseDamageToPlayer > 0) {
            $exchangeDamageToOpponent += $baseDamageToPlayer;
        }
        if ($state->opponentMirrorActive && $baseDamageToOpponent > 0) {
            $exchangeDamageToPlayer += $baseDamageToOpponent;
        }

        $exchange = [
            'leaderIsPlayer' => $leaderIsPlayer,
            'leaderFace' => $leaderFace->value,
            'leaderCount' => $leaderCount,
            'responderFace' => null !== $responderMove ? $responderMove['face']->value : null,
            'responderCount' => $responderMove['count'] ?? 0,
            'damageToPlayer' => $exchangeDamageToPlayer,
            'damageToOpponent' => $exchangeDamageToOpponent,
        ];

        $state->exchanges[] = $exchange;

        return $exchange;
    }

    /**
     * @return int bonus unblockable damage to apply to the OTHER side (0
     *             for Flip/Reroll/DamageMirror, whose effects aren't direct damage)
     */
    private function applyActionAbility(ExchangeRoundState $state, bool $isActingSidePlayer, int $count, AbilityChoice $choice): int
    {
        $alreadyTriggered = $isActingSidePlayer ? $state->playerAbilityTriggered : $state->opponentAbilityTriggered;

        if ($alreadyTriggered || AbilityType::Flip === $choice->ability) {
            return $this->applyFlip($state, $isActingSidePlayer, $count, $choice->targets);
        }

        $cost = $choice->ability->fixedCost() ?? $count;
        if ($count < $cost) {
            return $this->applyFlip($state, $isActingSidePlayer, $count, $choice->targets);
        }

        if ($isActingSidePlayer) {
            $state->playerAbilityTriggered = true;
        } else {
            $state->opponentAbilityTriggered = true;
        }

        $bonus = match ($choice->ability) {
            AbilityType::UnblockableDamage => $cost,
            AbilityType::Reroll => $this->applyReroll($state, $isActingSidePlayer),
            AbilityType::DamageMirror => $this->activateMirror($state, $isActingSidePlayer),
            AbilityType::Flip => 0, // unreachable — handled above
        };

        $leftover = $count - $cost;
        if ($leftover > 0) {
            $bonus += $this->applyFlip($state, $isActingSidePlayer, $leftover, $choice->targets);
        }

        return $bonus;
    }

    private function applyFlip(ExchangeRoundState $state, bool $isActingSidePlayer, int $count, array $declaredTargets): int
    {
        if ($count <= 0) {
            return 0;
        }

        $targetThrows = $isActingSidePlayer ? $state->opponentThrows : $state->playerThrows;
        $targetUsed = $isActingSidePlayer ? $state->opponentUsed : $state->playerUsed;

        foreach ($this->chooseFlipTargets($declaredTargets, $targetThrows, $targetUsed, $count) as $i) {
            if ($isActingSidePlayer) {
                $state->opponentThrows[$i] = $state->opponentThrows[$i]->flipped();
            } else {
                $state->playerThrows[$i] = $state->playerThrows[$i]->flipped();
            }
        }

        return 0;
    }

    /**
     * @param int[]      $declaredTargets
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

    private function applyReroll(ExchangeRoundState $state, bool $isActingSidePlayer): int
    {
        if ($isActingSidePlayer) {
            foreach ($state->playerThrows as $i => $throw) {
                if (!$state->playerUsed[$i]) {
                    $state->playerThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB);
                }
            }
        } else {
            foreach ($state->opponentThrows as $i => $throw) {
                if (!$state->opponentUsed[$i]) {
                    $state->opponentThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB);
                }
            }
        }

        return 0;
    }

    private function activateMirror(ExchangeRoundState $state, bool $isActingSidePlayer): int
    {
        if ($isActingSidePlayer) {
            $state->playerMirrorActive = true;
        } else {
            $state->opponentMirrorActive = true;
        }

        return 0;
    }

    private function markUsed(ExchangeRoundState $state, bool $isPlayerSide, BitFace $face, int $count): void
    {
        $throws = $isPlayerSide ? $state->playerThrows : $state->opponentThrows;
        $used = $isPlayerSide ? $state->playerUsed : $state->opponentUsed;

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
            $state->playerUsed = $used;
        } else {
            $state->opponentUsed = $used;
        }
    }
}
