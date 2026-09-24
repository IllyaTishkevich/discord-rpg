<?php

namespace App\Tests\Battle;

use App\Battle\AbilityChoice;
use App\Battle\BitThrow;
use App\Battle\InteractiveExchangeEngine;
use App\Enum\AbilityType;
use App\Enum\BitFace;
use App\Exception\InvalidExchangeMoveException;
use PHPUnit\Framework\TestCase;

class InteractiveExchangeEngineTest extends TestCase
{
    private function bit(BitFace $face, bool $advantage = false, int $multiplier = 1): BitThrow
    {
        return new BitThrow($face, $face, $advantage, $advantage, $face, $advantage, $multiplier, $multiplier, $multiplier);
    }

    public function testPlayerLeadsAndBotRespondsInOneCall(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Attack)],
            [$this->bit(BitFace::Defense)],
        );

        self::assertSame('lead', $engine->currentTurn($state));

        ['state' => $state, 'exchange' => $exchange] = $engine->submitLead($state, [0, 1], null, AbilityChoice::flip());

        // 2 attacks vs 1 defense: 1 gets through. Both sides now empty —
        // opponent had only 1 bit, player used both of its bits at once.
        self::assertSame(1, $exchange['damageToOpponent']);
        self::assertSame(0, $exchange['damageToPlayer']);
        self::assertSame('over', $engine->currentTurn($state));
    }

    public function testBotLeadsFirstAndPlayerMustRespond(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        self::assertSame('respond', $engine->currentTurn($state));
        self::assertSame(['face' => 'attack', 'count' => 1, 'icon' => null], $engine->getIncomingMove($state));

        ['state' => $state, 'exchange' => $exchange] = $engine->submitRespond($state, [0]);

        // Player blocks the incoming attack with its one defense bit.
        self::assertSame(0, $exchange['damageToPlayer']);
        self::assertSame(0, $exchange['damageToOpponent']);
        self::assertSame('over', $engine->currentTurn($state));
    }

    public function testPassingOnRespondTakesFullDamage(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action)],
            [$this->bit(BitFace::Attack, true)],
        );

        self::assertSame('respond', $engine->currentTurn($state));

        ['exchange' => $exchange] = $engine->submitRespond($state, []);

        self::assertSame(1, $exchange['damageToPlayer']);
    }

    public function testExhaustedSideStopsAndOtherSideKeepsSwingingViaAutoAdvance(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Action), $this->bit(BitFace::Action)],
            [$this->bit(BitFace::Defense)],
        );

        ['state' => $state, 'exchange' => $ex1] = $engine->submitLead($state, [0], null, AbilityChoice::flip());
        // Opponent's single defense bit fully blocks the incoming attack and
        // is consumed doing so (a response can only ever be defense now —
        // see docs/COMBAT_V2_DESIGN.md §4 — so this is the only way it
        // could have used it at all).
        self::assertSame(0, $ex1['damageToOpponent']);
        self::assertSame(0, $ex1['damageToPlayer']);

        // Opponent is now empty; player still has 2 action bits — the
        // round must NOT be over yet, and it's the player's turn to lead
        // again (not an auto-advance situation, since the player is the
        // one with bits left).
        self::assertSame('lead', $engine->currentTurn($state));

        ['state' => $state, 'exchange' => $ex2] = $engine->submitLead($state, [1, 2], new AbilityChoice(AbilityType::UnblockableDamage), AbilityChoice::flip());
        self::assertSame(2, $ex2['damageToOpponent']);
        self::assertSame(0, $ex2['damageToPlayer']);
        self::assertSame('over', $engine->currentTurn($state));
    }

    public function testAutoAdvanceResolvesBotSoloExchangeWhenPlayerIsEmpty(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, true)],
            [$this->bit(BitFace::Attack), $this->bit(BitFace::Defense)],
        );

        // Player leads with its only bit; opponent blocks with its defense
        // (heuristic prefers blocking an incoming attack), leaving its
        // separate Attack bit unused. Player is now empty.
        ['state' => $state, 'exchange' => $ex1] = $engine->submitLead($state, [0], null, AbilityChoice::flip());
        self::assertSame(0, $ex1['damageToOpponent']);
        self::assertSame(0, $ex1['damageToPlayer']);

        // autoAdvance() must resolve the opponent's leftover Attack bit
        // unopposed rather than treating the round as already over just
        // because the player ran out first.
        ['state' => $state, 'exchange' => $exchange] = $engine->autoAdvance($state, AbilityChoice::flip());

        self::assertNotNull($exchange);
        self::assertSame(1, $exchange['damageToPlayer']);
        self::assertSame(0, $exchange['damageToOpponent']);
        self::assertSame('over', $engine->currentTurn($state));
    }

    public function testCannotLeadWhenItIsNotYourTurn(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        $this->expectException(InvalidExchangeMoveException::class);
        $engine->submitLead($state, [0], null, AbilityChoice::flip());
    }

    public function testCannotMixDifferentFacesInOneMove(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack)],
        );

        $this->expectException(InvalidExchangeMoveException::class);
        $engine->submitLead($state, [0, 1], null, AbilityChoice::flip());
    }

    public function testRespondingWithAnAttackBitIsRejected(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        self::assertSame('respond', $engine->currentTurn($state));

        $this->expectException(InvalidExchangeMoveException::class);
        $this->expectExceptionMessage('You can only respond with defense bits.');
        $engine->submitRespond($state, [0]);
    }

    public function testRespondingWithAnActionBitIsRejected(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        self::assertSame('respond', $engine->currentTurn($state));

        $this->expectException(InvalidExchangeMoveException::class);
        $this->expectExceptionMessage('You can only respond with defense bits.');
        $engine->submitRespond($state, [0]);
    }

    public function testDestroyPermanentlyRemovesAnUnusedOpponentBit(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, true), $this->bit(BitFace::Action, true)],
            [$this->bit(BitFace::Attack), $this->bit(BitFace::Defense)],
        );

        // Player leads with both action bits, affording Destroy's fixed
        // cost of 2. No declared target — auto-fallback picks an attack
        // bit first (same priority as Flip), destroying it before it ever
        // gets a turn to lead.
        ['state' => $state, 'exchange' => $ex1] = $engine->submitLead($state, [0, 1], new AbilityChoice(AbilityType::Destroy), AbilityChoice::flip());
        self::assertSame(0, $ex1['damageToOpponent']);
        self::assertSame(0, $ex1['damageToPlayer']);

        // Opponent's only bit left is its Defense — nothing left to
        // threaten the player with, so its solo lead deals no damage.
        ['state' => $state, 'exchange' => $ex2] = $engine->autoAdvance($state, AbilityChoice::flip());
        self::assertNotNull($ex2);
        self::assertSame(0, $ex2['damageToOpponent']);
        self::assertSame(0, $ex2['damageToPlayer']);
        self::assertSame('over', $engine->currentTurn($state));
    }

    public function testDoublePermanentlyDoublesTheMultiplierOfOwnUnusedBit(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, true), $this->bit(BitFace::Action, true), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
        );

        // Player leads with both action bits, affording Double's fixed cost
        // of 2. No declared target — auto-fallback picks its own remaining
        // Defense bit (same priority as Flip/Destroy), doubling its
        // multiplier from ×1 to ×2.
        ['state' => $state, 'exchange' => $ex1] = $engine->submitLead($state, [0, 1], new AbilityChoice(AbilityType::Double), AbilityChoice::flip());
        self::assertSame(0, $ex1['damageToOpponent']);
        self::assertSame(0, $ex1['damageToPlayer']);

        // Bot's only bit (a ×3 attack) still needs its own solo lead —
        // autoAdvance() pauses here since the player still has its
        // (now-doubled) defense bit left to respond with.
        ['state' => $state, 'exchange' => $ex2] = $engine->autoAdvance($state, AbilityChoice::flip());
        self::assertNull($ex2);
        self::assertSame('respond', $engine->currentTurn($state));

        // The doubled (×2) defense blocks 2 of the ×3 attack, leaving 1
        // through — an un-doubled (×1) defense would have left 2 through.
        ['exchange' => $ex3] = $engine->submitRespond($state, [2]);
        self::assertSame(1, $ex3['damageToPlayer']);
        self::assertSame(0, $ex3['damageToOpponent']);
    }

    public function testFlipDuringLeadMutatesTargetedOpponentBit(): void
    {
        $engine = new InteractiveExchangeEngine();
        $opponentBit = new BitThrow(BitFace::Defense, BitFace::Attack, false, false, BitFace::Defense, false);

        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, true)],
            [$opponentBit],
        );

        ['state' => $state] = $engine->submitLead($state, [0], AbilityChoice::flip(targets: [0]), AbilityChoice::flip());

        self::assertSame(BitFace::Attack, $state->opponentThrows[0]->thrownFace);
    }

    public function testPassLeadHandsInitiativeToTheBotWithoutConsumingBits(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, true)],
            [$this->bit(BitFace::Attack)],
        );

        self::assertSame('lead', $engine->currentTurn($state));

        $state = $engine->passLead($state);

        self::assertFalse($state->leaderIsPlayer);
        self::assertSame(1, $state->remainingCount(true), 'passing must not consume the passing side\'s bit');
    }

    public function testPassLeadThrowsWhenItIsNotYourTurnToLead(): void
    {
        $engine = new InteractiveExchangeEngine();
        // Bot leads first (more advantage) and, since the player has a bit
        // to respond with, autoAdvance() pauses here rather than resolving
        // inline — so it's genuinely the player's turn to *respond*, not lead.
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        self::assertSame('respond', $engine->currentTurn($state));

        $this->expectException(InvalidExchangeMoveException::class);
        $engine->passLead($state);
    }

    // -----------------------------------------------------------------
    // PvP (both sides real — no bot auto-play): startPvpRound(),
    // turnForSide(), submitPvpLead()/passPvpLead(), submitPvpRespond().
    // -----------------------------------------------------------------

    public function testStartPvpRoundNeverAutoPlaysEvenWhenOpponentLeads(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        // Unlike startRound(), nobody has moved yet — opponent has priority
        // (more advantage) but must still submit their own lead explicitly.
        self::assertFalse($state->leaderIsPlayer);
        self::assertNull($state->pendingLeaderMove);
        self::assertSame('lead', $engine->turnForSide($state, false));
        self::assertSame('wait', $engine->turnForSide($state, true));
    }

    public function testSubmitPvpLeadPausesInsteadOfAutoResolving(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Attack, true)],
            [$this->bit(BitFace::Defense)],
        );

        $state = $engine->submitPvpLead($state, true, [0], null);

        self::assertSame(['face' => 'attack', 'count' => 1, 'bonus' => 0, 'icon' => null], $state->pendingLeaderMove);
        self::assertSame('respond', $engine->turnForSide($state, false));
        self::assertSame('wait', $engine->turnForSide($state, true));
    }

    public function testSubmitPvpRespondResolvesAndAlternatesLeadToResponder(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Defense), $this->bit(BitFace::Attack)],
        );

        $state = $engine->submitPvpLead($state, true, [0], null);
        ['state' => $state, 'exchange' => $exchange] = $engine->submitPvpRespond($state, false, [0]);

        self::assertSame(0, $exchange['damageToOpponent']);
        self::assertTrue($exchange['leaderIsPlayer']);
        self::assertNull($state->pendingLeaderMove);
        // The responder (opponent) leads next — not a hardcoded side, as it
        // would be if this reused PvE's player-only submitRespond().
        self::assertSame('lead', $engine->turnForSide($state, false));
        self::assertSame('wait', $engine->turnForSide($state, true));
    }

    public function testPassPvpLeadHandsInitiativeWithoutConsumingBits(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Attack, true)],
            [$this->bit(BitFace::Attack)],
        );

        $state = $engine->passPvpLead($state, true);

        self::assertSame('lead', $engine->turnForSide($state, false));
        self::assertSame(1, $state->remainingCount(true), 'passing must not consume the passing side\'s bit');
    }

    public function testCannotSubmitPvpLeadOutOfTurn(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, true)],
        );

        $this->expectException(InvalidExchangeMoveException::class);
        $engine->submitPvpLead($state, true, [0], null);
    }

    public function testCannotSubmitPvpRespondWithoutAPendingLead(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Attack, true)],
            [$this->bit(BitFace::Defense)],
        );

        $this->expectException(InvalidExchangeMoveException::class);
        $engine->submitPvpRespond($state, false, [0]);
    }

    public function testPvpOneSideExhaustedOtherContinuesSolo(): void
    {
        $engine = new InteractiveExchangeEngine();
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Attack)],
            [$this->bit(BitFace::Defense)],
        );

        $state = $engine->submitPvpLead($state, true, [0], null);
        ['state' => $state] = $engine->submitPvpRespond($state, false, [0]);

        // Opponent is now fully spent (their only bit is used) but the
        // player still has one attack bit left — player must keep leading
        // solo, same "continue until both sides run out" rule as PvE.
        self::assertSame(0, $state->remainingCount(false));
        self::assertSame('lead', $engine->turnForSide($state, true));

        $state = $engine->submitPvpLead($state, true, [1], null);
        // Opponent must still explicitly respond (pass, having nothing left)
        // to resolve the exchange — nobody auto-plays their empty hand.
        self::assertSame('respond', $engine->turnForSide($state, false));

        ['state' => $state, 'exchange' => $exchange] = $engine->submitPvpRespond($state, false, []);
        self::assertSame(1, $exchange['damageToOpponent']);
        self::assertTrue($state->isOver());
    }

    // -----------------------------------------------------------------
    // Multiplier: a face's damage/blocking/action-point contribution is
    // its multiplier, not a flat 1 per activated bit.
    // -----------------------------------------------------------------

    public function testMultipliedLeadDealsDamageEqualToItsMultiplier(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
            [$this->bit(BitFace::Defense)],
        );

        ['exchange' => $exchange] = $engine->submitLead($state, [0], null, AbilityChoice::flip());

        // ×3 attack vs a single (×1) defense: 3 - 1 = 2 gets through.
        self::assertSame(2, $exchange['damageToOpponent']);
    }

    public function testMultipliedResponseBlocksProportionally(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Defense, multiplier: 3)],
            [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
        );

        self::assertSame('respond', $engine->currentTurn($state));

        ['exchange' => $exchange] = $engine->submitRespond($state, [0]);

        // A single ×3 defense bit fully blocks a ×3 attack.
        self::assertSame(0, $exchange['damageToPlayer']);
    }

    public function testMultipliedActionFaceGrantsProportionalActionPoints(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, advantage: true, multiplier: 2)],
            [$this->bit(BitFace::Attack)],
        );

        ['exchange' => $exchange] = $engine->submitLead($state, [0], new AbilityChoice(AbilityType::UnblockableDamage), AbilityChoice::flip());

        // A single ×2 action bit banks 2 action points — enough to afford
        // UnblockableDamage's fixed cost of 2 (not 1, which a literal
        // bit-count would have given).
        self::assertSame(2, $exchange['damageToOpponent']);
    }

    /**
     * Regression test for a real bug this multiplier work surfaced:
     * validateAndConsumeMove() used to mark the generic "first N unused
     * bits of this face" as used, rather than the *specific* indices the
     * player actually selected — invisible when same-face bits were fully
     * interchangeable, but wrong now that they can carry different
     * multipliers (and always was a latent bug: the selected bit stayed
     * "unused" while a different, unselected one silently went dark).
     */
    public function testSelectingASpecificBitMarksThatExactIndexUsedNotTheFirstMatchingOne(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            // Two attack bits with different multipliers, plus a defense
            // bit for the bot so it doesn't resolve unopposed.
            [$this->bit(BitFace::Attack, advantage: true, multiplier: 1), $this->bit(BitFace::Attack, multiplier: 5)],
            [$this->bit(BitFace::Defense)],
        );

        // Explicitly lead with index 1 (the ×5 bit), not index 0.
        ['state' => $state, 'exchange' => $exchange] = $engine->submitLead($state, [1], null, AbilityChoice::flip());

        self::assertSame(4, $exchange['damageToOpponent'], 'damage must reflect the ×5 bit actually selected, not the ×1 one');
        self::assertTrue($state->playerUsed[1], 'the selected index must be marked used');
        self::assertFalse($state->playerUsed[0], 'the untouched index must remain available');
    }

    // -----------------------------------------------------------------
    // Empty face: never activates, excluded from the round entirely.
    // -----------------------------------------------------------------

    public function testEmptyBitCannotBeSelectedAsALeadMove(): void
    {
        $engine = new InteractiveExchangeEngine(static fn (): bool => true);
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Empty, true)],
            [$this->bit(BitFace::Attack)],
        );

        self::assertSame('lead', $engine->currentTurn($state));

        $this->expectException(InvalidExchangeMoveException::class);
        $this->expectExceptionMessage('Empty-faced bits cannot be played.');
        $engine->submitLead($state, [0], null, AbilityChoice::flip());
    }

    public function testEmptyBitDoesNotCountTowardAdvantage(): void
    {
        // Tie-break forced to "player leads" if reached — this must never
        // be reached: if Empty's advantage flag counted, this would tie at
        // 1-1; excluded correctly, it's a clean 0-1, opponent leads outright.
        $engine = new InteractiveExchangeEngine(static fn (): bool => true);
        $state = $engine->startPvpRound(
            [$this->bit(BitFace::Empty, advantage: true), $this->bit(BitFace::Defense)],
            [$this->bit(BitFace::Attack, advantage: true)],
        );

        self::assertFalse($state->leaderIsPlayer, 'opponent (1 real advantage) must lead over player (0 — Empty\'s advantage must not count)');
    }

    public function testFlipCanReviveAnEmptyBitByTargetingItExplicitly(): void
    {
        $engine = new InteractiveExchangeEngine();
        // Opponent's only bit currently shows Empty but flips to Attack.
        $opponentEmptyBit = new BitThrow(BitFace::Empty, BitFace::Attack, false, false, BitFace::Empty, false);

        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Action, true)],
            [$opponentEmptyBit],
        );

        // Player leads (only bit: Action) and flips opponent's Empty bit —
        // explicitly targeted, so it's a valid target despite showing Empty
        // (only the *auto-fallback* target picker skips Empty/Action faces).
        ['state' => $state] = $engine->submitLead($state, [0], AbilityChoice::flip(targets: [0]), AbilityChoice::flip());
        self::assertSame(BitFace::Attack, $state->opponentThrows[0]->thrownFace);

        // It's no longer inert — it gets its own exchange as opponent's
        // lead once autoAdvance() runs, proving it actually rejoined the
        // round rather than just cosmetically changing face.
        ['exchange' => $ex2] = $engine->autoAdvance($state, AbilityChoice::flip());
        self::assertNotNull($ex2);
        self::assertSame(1, $ex2['damageToPlayer']);
        self::assertSame(0, $ex2['damageToOpponent']);
    }
}
