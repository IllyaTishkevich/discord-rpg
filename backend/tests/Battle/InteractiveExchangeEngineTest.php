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
    private function bit(BitFace $face, bool $advantage = false): BitThrow
    {
        return new BitThrow($face, $face, $advantage, $advantage, $face, $advantage);
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
        self::assertSame(['face' => 'attack', 'count' => 1], $engine->getIncomingMove($state));

        ['state' => $state, 'exchange' => $exchange] = $engine->submitRespond($state, [0], null);

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

        ['exchange' => $exchange] = $engine->submitRespond($state, [], null);

        self::assertSame(1, $exchange['damageToPlayer']);
    }

    public function testExhaustedSideStopsAndOtherSideKeepsSwingingViaAutoAdvance(): void
    {
        $engine = new InteractiveExchangeEngine();
        ['state' => $state] = $engine->startRound(
            [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Action), $this->bit(BitFace::Action)],
            [$this->bit(BitFace::Attack)],
        );

        ['state' => $state, 'exchange' => $ex1] = $engine->submitLead($state, [0], null, AbilityChoice::flip());
        self::assertSame(1, $ex1['damageToOpponent']);
        self::assertSame(1, $ex1['damageToPlayer']);

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
}
