<?php

namespace App\Tests\Battle;

use App\Battle\AbilityChoice;
use App\Battle\BitThrow;
use App\Battle\ExchangeResolver;
use App\Enum\AbilityType;
use App\Enum\BitFace;
use PHPUnit\Framework\TestCase;

class ExchangeResolverTest extends TestCase
{
    private function bit(BitFace $face, bool $advantage = false, int $multiplier = 1): BitThrow
    {
        return new BitThrow($face, $face, $advantage, $advantage, $face, $advantage, $multiplier, $multiplier, $multiplier);
    }

    public function testPartialBlockLeavesExcessAttackAsDamage(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Attack)],
            opponentThrows: [$this->bit(BitFace::Defense)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        // Player leads (has advantage) with both attacks at once; opponent's
        // single defense blocks only one of them.
        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testMutualAttackDealsDamageToBothSidesWhenNeitherDefends(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testLeaderCommittingAttackFirstLeavesItsOwnDefenseUnusedThisExchange(): void
    {
        // X leads (more advantage) and — per the default heuristic —
        // commits its Attack first, leaving its Defense unused for THIS
        // exchange. Y (no defense of its own) trades blows back. Once Y
        // (a single bit) is exhausted, X gets one more solo exchange with
        // its leftover Defense — but with no incoming attack to block, a
        // defense-only move deals no damage either way, so the total is
        // unaffected. Contrast with the next test, where a responder's
        // held-back defense actually gets to block something.
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Defense, true)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testRespondingSideCanReactivelyBlockWithHeldBackDefense(): void
    {
        // Same two hands as above, but this time the OTHER side leads
        // (more advantage) — the side with Attack+Defense is now reacting,
        // and its response heuristic correctly holds Defense back only to
        // block the incoming attack, taking no damage from that exchange.
        // Player is then out of bits after its one attack, but the
        // opponent still has its own Attack bit left over — it gets a
        // second, unopposed solo exchange with it (see docs/COMBAT_V2_DESIGN.md
        // §3: the round isn't over just because one side emptied out first).
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true)],
            opponentThrows: [$this->bit(BitFace::Attack), $this->bit(BitFace::Defense)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testUnblockableDamageBypassesDefenseEntirely(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Action), $this->bit(BitFace::Action)],
            opponentThrows: [$this->bit(BitFace::Defense), $this->bit(BitFace::Defense)],
            playerChoice: new AbilityChoice(AbilityType::UnblockableDamage),
            opponentChoice: AbilityChoice::flip(),
        );

        // Exchange 1: player's attack is fully blocked by one defense (0
        // damage). Exchange 2: opponent leads with its remaining defense
        // (no effect on its own); player responds with both action bits,
        // triggering 2 unblockable damage that defense can't touch.
        self::assertSame(2, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testDamageMirrorReflectsDamageTakenBackAtOpponent(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action), $this->bit(BitFace::Action)],
            opponentThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Attack, true)],
            playerChoice: new AbilityChoice(AbilityType::DamageMirror),
            opponentChoice: AbilityChoice::flip(),
        );

        // Opponent leads (has advantage) with both attacks; player has no
        // defense, so it takes the full 2 damage — but DamageMirror was
        // active the moment player committed its action bits in response,
        // so that same 2 damage bounces back onto the opponent too.
        self::assertSame(2, $result->damageToPlayer);
        self::assertSame(2, $result->damageToOpponent);
    }

    public function testFlipMutatesTheTargetedOpponentBitFace(): void
    {
        $resolver = new ExchangeResolver();

        // Both opponent bits currently show "defense" but flip to "attack".
        $opponentBitShowingDefense = new BitThrow(BitFace::Defense, BitFace::Attack, false, false, BitFace::Defense, false);
        $untouchedOpponentBit = new BitThrow(BitFace::Defense, BitFace::Attack, false, false, BitFace::Defense, false);

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action, true)],
            opponentThrows: [$opponentBitShowingDefense, $untouchedOpponentBit],
            playerChoice: AbilityChoice::flip(targets: [0]),
            opponentChoice: AbilityChoice::flip(),
        );

        // Player leads (only bit: Action) and flips opponent's bit #0 from
        // defense to attack. Opponent then responds with that freshly
        // flipped attack (its only other bit is still defense, but nothing
        // is threatening it, so the default heuristic prefers attacking).
        // Player, now out of bits, takes that attack for 1 damage; the
        // round ends there even though the opponent still has one bit left.
        self::assertSame(1, $result->damageToPlayer);
        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(BitFace::Attack, $result->opponentFaces[0]);
        self::assertSame(BitFace::Defense, $result->opponentFaces[1]);
    }

    public function testExhaustedSideStopsParticipatingButRoundContinuesForTheOther(): void
    {
        // Opponent has only 1 bit; player has an attack plus two leftover
        // action bits. Once the opponent's single bit is spent trading
        // blows, the round does NOT end — player keeps leading solo with
        // its remaining action bits (unopposed, no one left to respond),
        // converting them to unblockable damage that would otherwise have
        // been silently discarded.
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Action), $this->bit(BitFace::Action)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: new AbilityChoice(AbilityType::UnblockableDamage),
            opponentChoice: AbilityChoice::flip(),
        );

        // Exchange 1: player leads with its one attack; opponent (no
        // defense) trades back with its only bit — 1 damage each way.
        // Opponent is now empty. Exchange 2: player leads solo with its 2
        // remaining action bits, converting them to 2 unblockable damage
        // (nothing left on the other side to respond, let alone block).
        self::assertSame(1 + 2, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testTieOnAdvantageBreaksRandomlyViaInjectedCoinFlip(): void
    {
        $resolver = new ExchangeResolver(static fn (): bool => true); // always "player leads"

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack)],
            opponentThrows: [$this->bit(BitFace::Attack), $this->bit(BitFace::Defense)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        // Same shape as testRespondingSideCanReactivelyBlockWithHeldBackDefense,
        // but the tie is forced via the injected coin flip rather than by a
        // real advantage difference — confirms the hook actually drives it.
        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    // -----------------------------------------------------------------
    // Multiplier: a face's damage/blocking/action-point contribution is
    // its multiplier, not a flat 1 per activated bit.
    // -----------------------------------------------------------------

    public function testMultipliedAttackDealsDamageEqualToItsMultiplier(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
            opponentThrows: [$this->bit(BitFace::Defense)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        // ×3 attack vs a single (×1) defense: 3 - 1 = 2 gets through.
        self::assertSame(2, $result->damageToOpponent);
    }

    public function testMultipliedDefenseBlocksProportionally(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
            opponentThrows: [$this->bit(BitFace::Defense, multiplier: 3)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        // ×3 attack fully blocked by a single ×3 defense bit — one bit is
        // enough, matching what its multiplier is actually worth.
        self::assertSame(0, $result->damageToOpponent);
    }

    public function testMultipliedActionFaceGrantsProportionalActionPoints(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action, advantage: true, multiplier: 2)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: new AbilityChoice(AbilityType::UnblockableDamage),
            opponentChoice: AbilityChoice::flip(),
        );

        // A single ×2 action bit banks 2 action points — enough to afford
        // UnblockableDamage's fixed cost of 2, dealing 2 unblockable damage
        // (not 1, which is what a literal bit-count would have given).
        self::assertSame(2, $result->damageToOpponent);
    }
}
