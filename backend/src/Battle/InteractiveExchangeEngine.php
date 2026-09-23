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

        // An Empty-showing bit never participates in the round at all — not
        // even toward who leads it, regardless of how its advantage flag
        // happens to be configured.
        $countsTowardAdvantage = static fn (BitThrow $t): bool => BitFace::Empty !== $t->thrownFace && $t->thrownAdvantage;
        $playerAdvantage = \count(array_filter($playerThrows, $countsTowardAdvantage));
        $opponentAdvantage = \count(array_filter($opponentThrows, $countsTowardAdvantage));
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
        ['face' => $face, 'amount' => $amount] = $this->validateAndConsumeMove($state, $isPlayerSide, $indices);
        $bonus = BitFace::Action === $face
            ? $this->applyActionAbility($state, $isPlayerSide, $amount, $ability ?? AbilityChoice::flip())
            : 0;

        $state->pendingLeaderMove = ['face' => $face->value, 'count' => $amount, 'bonus' => $bonus];

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
     * PvE-only equivalent of passPvpLead() — hardcoded to the player
     * forfeiting, since only the player can ever fail to act in PvE (the
     * bot always resolves inline the moment it's given the chance, via
     * autoAdvance() — see BattleService::applyMoveTimeoutIfExpired(), the
     * only caller). Hands the lead to the bot without consuming any bits.
     */
    public function passLead(ExchangeRoundState $state): ExchangeRoundState
    {
        if ('lead' !== $this->currentTurn($state)) {
            throw new InvalidExchangeMoveException('It is not your turn to lead this exchange.');
        }

        $state->leaderIsPlayer = false;

        return $state;
    }

    /**
     * PvP-only: the given side responds to the other side's already-pending
     * lead move (or passes, with an empty $indices — full damage from the
     * incoming move, per docs/COMBAT_V2_DESIGN.md §4). A response may only
     * use defense bits — never attack or action, so a response can never
     * itself trigger an ability or deal damage back to the leader.
     *
     * @param int[] $indices
     *
     * @return array{state: ExchangeRoundState, exchange: array}
     */
    public function submitPvpRespond(ExchangeRoundState $state, bool $isPlayerSide, array $indices): array
    {
        if ('respond' !== $this->turnForSide($state, $isPlayerSide)) {
            throw new InvalidExchangeMoveException('You are not being asked to respond right now.');
        }

        $leaderMove = $state->pendingLeaderMove;
        $leaderFace = BitFace::from($leaderMove['face']);
        $leaderAmount = $leaderMove['count'];
        $leaderBonus = $leaderMove['bonus'];

        $responderFace = null;
        $responderAmount = 0;
        if ([] !== $indices) {
            ['face' => $responderFace, 'amount' => $responderAmount] = $this->validateAndConsumeMove($state, $isPlayerSide, $indices, BitFace::Defense);
        }

        $responderMove = null === $responderFace ? null : ['face' => $responderFace, 'amount' => $responderAmount];
        $leaderIsPlayerForExchange = !$isPlayerSide;
        $exchange = $this->resolveExchange($state, $leaderIsPlayerForExchange, $leaderFace, $leaderAmount, $leaderBonus, $responderMove, 0);
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

        ['face' => $face, 'amount' => $amount] = $this->validateAndConsumeMove($state, true, $indices);
        $leaderBonus = BitFace::Action === $face
            ? $this->applyActionAbility($state, true, $amount, $ability ?? AbilityChoice::flip())
            : 0;

        $responderMove = $this->chooseResponseMove($state, false, $face, $amount);
        $responderBonus = 0;
        if (null !== $responderMove) {
            $this->markUsed($state, false, $responderMove['face'], $responderMove['count']);
            if (BitFace::Action === $responderMove['face']) {
                $responderBonus = $this->applyActionAbility($state, false, $responderMove['amount'], $botChoice);
            }
        }

        $exchange = $this->resolveExchange($state, true, $face, $amount, $leaderBonus, $responderMove, $responderBonus);
        $state->leaderIsPlayer = false;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * The player responds to the bot's already-committed lead move. An
     * empty $indices array means passing (no response — full damage from
     * the incoming move, per docs/COMBAT_V2_DESIGN.md §4). A response may
     * only use defense bits — never attack or action, so a response can
     * never itself trigger an ability or deal damage back to the bot.
     *
     * @param int[] $indices
     *
     * @return array{state: ExchangeRoundState, exchange: array}
     */
    public function submitRespond(ExchangeRoundState $state, array $indices): array
    {
        if ('respond' !== $this->currentTurn($state)) {
            throw new InvalidExchangeMoveException('You are not being asked to respond right now.');
        }

        $leaderMove = $state->pendingLeaderMove;
        $leaderFace = BitFace::from($leaderMove['face']);
        $leaderAmount = $leaderMove['count'];
        $leaderBonus = $leaderMove['bonus'];

        $responderFace = null;
        $responderAmount = 0;
        if (\count($indices) > 0) {
            ['face' => $responderFace, 'amount' => $responderAmount] = $this->validateAndConsumeMove($state, true, $indices, BitFace::Defense);
        }

        $responderMove = null === $responderFace ? null : ['face' => $responderFace, 'amount' => $responderAmount];
        $exchange = $this->resolveExchange($state, false, $leaderFace, $leaderAmount, $leaderBonus, $responderMove, 0);
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
            ? $this->applyActionAbility($state, false, $leaderMove['amount'], $botChoice)
            : 0;

        if ($state->remainingCount(true) > 0) {
            // Player can respond — pause here and let the caller ask them.
            $state->pendingLeaderMove = [
                'face' => $leaderMove['face']->value,
                'count' => $leaderMove['amount'],
                'bonus' => $leaderBonus,
            ];

            return ['state' => $state, 'exchange' => null];
        }

        // Player has nothing left to respond with — resolve unopposed.
        $exchange = $this->resolveExchange($state, false, $leaderMove['face'], $leaderMove['amount'], $leaderBonus, null, 0);
        $state->leaderIsPlayer = true;

        return ['state' => $state, 'exchange' => $exchange];
    }

    /**
     * @param int[] $indices
     *
     * @return array{face: BitFace, amount: int}
     */
    private function validateAndConsumeMove(ExchangeRoundState $state, bool $isPlayerSide, array $indices, ?BitFace $requiredFace = null): array
    {
        if ([] === $indices) {
            throw new InvalidExchangeMoveException('At least one bit must be selected to lead or respond.');
        }

        $throws = $isPlayerSide ? $state->playerThrows : $state->opponentThrows;
        $used = $isPlayerSide ? $state->playerUsed : $state->opponentUsed;

        $face = null;
        $amount = 0;
        $uniqueIndices = array_unique($indices);
        foreach ($uniqueIndices as $index) {
            if (!isset($throws[$index]) || ($used[$index] ?? true)) {
                throw new InvalidExchangeMoveException(\sprintf('Bit index %d is invalid or already used.', $index));
            }
            if (null === $face) {
                $face = $throws[$index]->thrownFace;
            } elseif ($face !== $throws[$index]->thrownFace) {
                throw new InvalidExchangeMoveException('All selected bits must show the same face.');
            }
            $amount += $throws[$index]->thrownMultiplier;
        }

        // Reject before consuming anything — a rejected move must never
        // leave the selected bits marked used. An Empty-showing bit never
        // participates in the round at all (docs/COMBAT_V2_DESIGN.md §1),
        // so it can never be led or responded with either.
        if (BitFace::Empty === $face) {
            throw new InvalidExchangeMoveException('Empty-faced bits cannot be played.');
        }

        if (null !== $requiredFace && $face !== $requiredFace) {
            throw new InvalidExchangeMoveException(\sprintf('You can only respond with %s bits.', $requiredFace->value));
        }

        // Mark exactly the chosen indices — NOT the generic "first N unused
        // of this face" scan that markUsed() does for the bot's own moves.
        // Those are interchangeable when picking for itself; a real player
        // picked *these specific* bits, and now that different bits of the
        // same face can carry different multipliers, marking the wrong
        // ones used would desync the board from what the player actually
        // selected (the bit they picked would stay selectable, and some
        // other untouched bit would incorrectly go dark).
        foreach ($uniqueIndices as $index) {
            if ($isPlayerSide) {
                $state->playerUsed[$index] = true;
            } else {
                $state->opponentUsed[$index] = true;
            }
        }

        return ['face' => $face, 'amount' => $amount];
    }

    /**
     * @return array{face: BitFace, count: int, amount: int}
     */
    private function chooseLeadMove(ExchangeRoundState $state, bool $isPlayerSide): array
    {
        foreach ([BitFace::Attack, BitFace::Action, BitFace::Defense] as $face) {
            $gathered = $state->gatherByFace($isPlayerSide, $face);
            if ($gathered['count'] > 0) {
                return ['face' => $face, 'count' => $gathered['count'], 'amount' => $gathered['amount']];
            }
        }

        throw new \LogicException('chooseLeadMove called with no remaining bits.');
    }

    /**
     * A response may only ever use defense bits (never attack or action —
     * see docs/COMBAT_V2_DESIGN.md §4) — and only bothers doing so when
     * there's actual incoming attack damage to block; otherwise (or with no
     * defense bits left) the bot passes, same as a player declining to
     * respond, leaving its other bits for when it's next its turn to lead.
     *
     * @return array{face: BitFace, count: int, amount: int}|null
     */
    private function chooseResponseMove(ExchangeRoundState $state, bool $isPlayerSide, BitFace $incomingFace, int $incomingAmount): ?array
    {
        if (BitFace::Attack !== $incomingFace) {
            return null;
        }

        // Only commit as much defense as actually needed to block — no
        // reason to burn a whole reserve blocking one small attack.
        $gathered = $state->gatherByFace($isPlayerSide, BitFace::Defense, $incomingAmount);

        return $gathered['count'] > 0 ? ['face' => BitFace::Defense, 'count' => $gathered['count'], 'amount' => $gathered['amount']] : null;
    }

    /**
     * @param array{face: BitFace, count: int, amount: int}|null $responderMove
     *
     * @return array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}
     */
    private function resolveExchange(
        ExchangeRoundState $state,
        bool $leaderIsPlayer,
        BitFace $leaderFace,
        int $leaderAmount,
        int $leaderBonus,
        ?array $responderMove,
        int $responderBonus,
    ): array {
        $leaderAttack = BitFace::Attack === $leaderFace ? $leaderAmount : 0;
        $leaderDefense = BitFace::Defense === $leaderFace ? $leaderAmount : 0;
        $responderAttack = null !== $responderMove && BitFace::Attack === $responderMove['face'] ? $responderMove['amount'] : 0;
        $responderDefense = null !== $responderMove && BitFace::Defense === $responderMove['face'] ? $responderMove['amount'] : 0;

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
            // NB: "count" here (and on responderCount below) means the
            // effective amount (sum of activated bits' multipliers), not a
            // literal bit tally — matches what actually drove the damage
            // above. Kept as "count" rather than renamed to "amount" so
            // already-persisted BattleRound.exchanges / in-flight
            // Battle.pendingExchangeState JSON stays shape-compatible.
            'leaderCount' => $leaderAmount,
            'responderFace' => null !== $responderMove ? $responderMove['face']->value : null,
            'responderCount' => $responderMove['amount'] ?? 0,
            'damageToPlayer' => $exchangeDamageToPlayer,
            'damageToOpponent' => $exchangeDamageToOpponent,
        ];

        $state->exchanges[] = $exchange;

        return $exchange;
    }

    /**
     * @param int $amount action points banked this move — sum of the
     *                    activated action bits' multipliers, not a literal
     *                    bit count
     *
     * @return int bonus unblockable damage to apply to the OTHER side (0
     *             for Flip/Reroll/DamageMirror, whose effects aren't direct damage)
     */
    private function applyActionAbility(ExchangeRoundState $state, bool $isActingSidePlayer, int $amount, AbilityChoice $choice): int
    {
        $alreadyTriggered = $isActingSidePlayer ? $state->playerAbilityTriggered : $state->opponentAbilityTriggered;

        if ($alreadyTriggered || AbilityType::Flip === $choice->ability) {
            return $this->applyFlip($state, $isActingSidePlayer, $amount, $choice->targets);
        }

        $cost = $choice->ability->fixedCost() ?? $amount;
        if ($amount < $cost) {
            return $this->applyFlip($state, $isActingSidePlayer, $amount, $choice->targets);
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
            AbilityType::Destroy => $this->applyDestroy($state, $isActingSidePlayer, $choice->targets),
            AbilityType::Double => $this->applyDouble($state, $isActingSidePlayer, $choice->targets),
            AbilityType::Flip => 0, // unreachable — handled above
        };

        $leftover = $amount - $cost;
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

        foreach ($this->chooseBitTargets($declaredTargets, $targetThrows, $targetUsed, $count) as $i) {
            if ($isActingSidePlayer) {
                $state->opponentThrows[$i] = $state->opponentThrows[$i]->flipped();
            } else {
                $state->playerThrows[$i] = $state->playerThrows[$i]->flipped();
            }
        }

        return 0;
    }

    /**
     * Permanently removes one of the opponent's not-yet-activated bits from
     * play — unlike Flip, it never comes back; the round just proceeds as
     * if that bit had never been thrown. No direct damage (bonus is always 0).
     *
     * @param int[] $declaredTargets index the player explicitly asked to destroy
     */
    private function applyDestroy(ExchangeRoundState $state, bool $isActingSidePlayer, array $declaredTargets): int
    {
        $targetThrows = $isActingSidePlayer ? $state->opponentThrows : $state->playerThrows;
        $targetUsed = $isActingSidePlayer ? $state->opponentUsed : $state->playerUsed;

        $chosen = $this->chooseBitTargets($declaredTargets, $targetThrows, $targetUsed, 1);
        if ([] === $chosen) {
            return 0;
        }

        if ($isActingSidePlayer) {
            $state->opponentUsed[$chosen[0]] = true;
        } else {
            $state->playerUsed[$chosen[0]] = true;
        }

        return 0;
    }

    /**
     * Permanently doubles the multiplier of one of the caster's OWN
     * not-yet-activated bits (unlike Flip/Destroy, which target the
     * opponent) — see BitThrow::doubled(). "Only once per bit" (docs/
     * COMBAT_V2_DESIGN.md §5) falls out for free: an ability can only ever
     * trigger once per side per round ($playerAbilityTriggered/
     * $opponentAbilityTriggered above), and every round starts from a fresh
     * throw, so there's never a second chance to double the same bit again.
     *
     * @param int[] $declaredTargets index the player explicitly asked to double
     */
    private function applyDouble(ExchangeRoundState $state, bool $isActingSidePlayer, array $declaredTargets): int
    {
        $ownThrows = $isActingSidePlayer ? $state->playerThrows : $state->opponentThrows;
        $ownUsed = $isActingSidePlayer ? $state->playerUsed : $state->opponentUsed;

        $chosen = $this->chooseBitTargets($declaredTargets, $ownThrows, $ownUsed, 1);
        if ([] === $chosen) {
            return 0;
        }

        $i = $chosen[0];
        if ($isActingSidePlayer) {
            $state->playerThrows[$i] = $state->playerThrows[$i]->doubled();
        } else {
            $state->opponentThrows[$i] = $state->opponentThrows[$i]->doubled();
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
    private function chooseBitTargets(array $declaredTargets, array $throws, array $used, int $maxCount): array
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
                    $state->playerThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB, $throw->multiplierA, $throw->multiplierB);
                }
            }
        } else {
            foreach ($state->opponentThrows as $i => $throw) {
                if (!$state->opponentUsed[$i]) {
                    $state->opponentThrows[$i] = BitThrow::random($throw->faceA, $throw->faceB, $throw->advantageA, $throw->advantageB, $throw->multiplierA, $throw->multiplierB);
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
