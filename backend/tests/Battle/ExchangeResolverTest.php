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

    public function testUnansweredAttacksTradeAcrossTwoSequentialExchanges(): void
    {
        // A response can only ever be defense now (docs/COMBAT_V2_DESIGN.md
        // §4) — neither side has any, so this is no longer a single
        // simultaneous "mutual attack" exchange (that mechanic is gone).
        // Instead: X leads, Y can't respond and just takes the hit, keeping
        // its own attack bit; lead alternates, Y leads with that same bit,
        // X has nothing left to block with either — same final numbers as
        // the old mutual-trade mechanic, reached via two one-sided exchanges.
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

    public function testHeldBackDefenseBlocksTheOpponentsCounterLeadNextExchange(): void
    {
        // X leads (more advantage) and — per the default heuristic —
        // commits its Attack first, leaving its Defense bit unused for THIS
        // exchange. Y has no defense of its own, so it can't respond at
        // all (a response can only ever be defense — see
        // docs/COMBAT_V2_DESIGN.md §4) — it just takes the hit and keeps
        // its own Attack bit for later. Once the lead alternates, Y leads
        // with that Attack, and NOW X's held-back Defense is available to
        // respond and block it fully.
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Defense, true)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
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
            playerThrows: [$this->bit(BitFace::Action, true), $this->bit(BitFace::Action, true)],
            opponentThrows: [$this->bit(BitFace::Attack), $this->bit(BitFace::Attack)],
            playerChoice: new AbilityChoice(AbilityType::DamageMirror),
            opponentChoice: AbilityChoice::flip(),
        );

        // A response can only ever be defense now (docs/COMBAT_V2_DESIGN.md
        // §4), so an ability can only ever be activated while LEADING, never
        // while responding. Player leads first (has advantage) with both
        // action bits, activating DamageMirror — nothing happens yet, it's
        // just a status effect for the rest of the round. Lead then
        // alternates to the opponent, who leads with both its attacks;
        // player has nothing left to block with, so it takes the full 2
        // damage — but with its mirror already active, that same 2 damage
        // bounces right back onto the opponent too.
        self::assertSame(2, $result->damageToPlayer);
        self::assertSame(2, $result->damageToOpponent);
    }

    public function testDestroyPermanentlyRemovesAnUnusedOpponentBit(): void
    {
        // Player leads first (2 advantage bits) with both action bits at
        // once, affording Destroy's fixed cost of 2. Opponent's only bit
        // (a lone, otherwise-unblockable attack — no declared target means
        // Destroy auto-picks an attack bit first, same priority as Flip's
        // fallback) is destroyed before it ever gets a turn to lead.
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action, true), $this->bit(BitFace::Action, true)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: new AbilityChoice(AbilityType::Destroy),
            opponentChoice: AbilityChoice::flip(),
        );

        // Contrast with testHeldBackDefenseBlocksTheOpponentsCounterLeadNextExchange,
        // where an unanswered lone opponent attack gets a free solo exchange
        // once the player empties out, dealing 1 damage. Here it never gets
        // the chance — the round ends after just the one exchange.
        self::assertCount(1, $result->exchanges);
        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testDoublePermanentlyDoublesTheMultiplierOfOwnUnusedBit(): void
    {
        // chooseLeadMove always prefers Attack over Action over Defense
        // (§6 heuristic), so the only bit Double can reliably target BEFORE
        // it's activated is a Defense bit (led last) — an Attack bit in the
        // same hand would always be led first, activated, and no longer be
        // a valid "not yet activated" target.
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action, true), $this->bit(BitFace::Action, true), $this->bit(BitFace::Defense)],
            opponentThrows: [$this->bit(BitFace::Attack, advantage: true, multiplier: 3)],
            playerChoice: new AbilityChoice(AbilityType::Double),
            opponentChoice: AbilityChoice::flip(),
        );

        // Exchange 1: player leads with both action bits, doubling its own
        // Defense bit's multiplier from ×1 to ×2 (no declared target —
        // auto-fallback picks it, same priority as Flip/Destroy). Exchange
        // 2: opponent leads with a ×3 attack; player's now-×2 defense
        // blocks 2 of it, leaving 1 through — an un-doubled (×1) defense
        // would have left 2 through instead.
        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
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
        // defense to attack. A response can only ever be defense now (see
        // docs/COMBAT_V2_DESIGN.md §4), and the incoming move is Action
        // (not Attack), so opponent can't respond at all this exchange —
        // it just passes, keeping both bits untouched. Lead alternates to
        // opponent, who leads with its now-flipped Attack bit; player is
        // already out of bits and takes it for 1 damage. Lead alternates
        // again for one final, harmless solo exchange where opponent leads
        // with its last (still-Defense) bit — nothing to block, no one
        // left to respond, so it deals no further damage either way.
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

        // Exchange 1: player leads with its one attack; opponent has no
        // defense, so it can't respond at all (a response can only ever be
        // defense — see docs/COMBAT_V2_DESIGN.md §4) — it just takes the 1
        // damage and keeps its own Attack bit for later. Exchange 2: lead
        // alternates to opponent, who leads with that same Attack bit;
        // player has no defense either, so it takes 1 damage right back —
        // opponent is now empty. Exchange 3: player leads solo with its 2
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

    // -----------------------------------------------------------------
    // Empty face: never activates, excluded from the round entirely.
    // -----------------------------------------------------------------

    public function testEmptyBitNeverParticipatesAndTheRoundEndsCleanly(): void
    {
        $resolver = new ExchangeResolver();

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Attack, true), $this->bit(BitFace::Empty)],
            opponentThrows: [$this->bit(BitFace::Attack)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        // Player leads (advantage) with its Attack bit — its Empty bit is
        // never a candidate to lead with. Opponent (no defense) takes the
        // hit and keeps its own Attack bit; lead alternates, opponent leads
        // with that Attack, player has nothing left to block with (its only
        // remaining bit is the inert Empty one, never offered as a
        // response) — same shape as two ordinary unanswered attacks, with
        // the Empty bit simply never entering into it. Exactly 2 exchanges:
        // the leftover Empty bit does NOT force a 3rd, unresolvable one.
        self::assertCount(2, $result->exchanges);
        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testEmptyBitDoesNotCountTowardAdvantage(): void
    {
        // Tie-break forced to "player leads" if reached — the point of this
        // test is that it must NOT be reached at all: if Empty's advantage
        // flag counted, this would tie at 1-1; excluded correctly, it's a
        // clean 0-1, opponent leads outright, and the injected tie-breaker
        // is never consulted.
        $resolver = new ExchangeResolver(static fn (): bool => true);

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Empty, advantage: true), $this->bit(BitFace::Defense)],
            opponentThrows: [$this->bit(BitFace::Attack, advantage: true)],
            playerChoice: AbilityChoice::flip(),
            opponentChoice: AbilityChoice::flip(),
        );

        self::assertFalse($result->exchanges[0]->leaderIsPlayer, 'opponent (1 real advantage) must lead over player (0 — the Empty bit\'s advantage must not count)');
    }

    public function testFlipCanReviveAnEmptyBitByTargetingItExplicitly(): void
    {
        $resolver = new ExchangeResolver();

        // Opponent's only bit currently shows Empty but flips to Attack.
        $opponentEmptyBit = new BitThrow(BitFace::Empty, BitFace::Attack, false, false, BitFace::Empty, false);

        $result = $resolver->resolveRound(
            playerThrows: [$this->bit(BitFace::Action, true)],
            opponentThrows: [$opponentEmptyBit],
            playerChoice: AbilityChoice::flip(targets: [0]),
            opponentChoice: AbilityChoice::flip(),
        );

        // Player leads (only bit: Action) and flips opponent's Empty bit —
        // explicitly targeted, so it's a valid target despite showing Empty
        // (only the *auto-fallback* target picker skips Empty/Action faces).
        // It now shows Attack and is no longer inert: lead alternates to
        // opponent, who leads with it for real damage — proving the bit
        // actually rejoined the round, not just cosmetically changed face.
        self::assertSame(BitFace::Attack, $result->opponentFaces[0]);
        self::assertCount(2, $result->exchanges);
        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }
}
