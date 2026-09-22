<?php

namespace App\Tests\Battle;

use App\Battle\BitThrow;
use App\Battle\CombatResolver;
use App\Enum\BitFace;
use PHPUnit\Framework\TestCase;

class CombatResolverTest extends TestCase
{
    private CombatResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CombatResolver();
    }

    private function fixed(BitFace $face): BitThrow
    {
        // A "coin" whose both faces are the same value always throws that value,
        // and flipping it is a no-op — useful for deterministic test fixtures.
        return new BitThrow($face, $face, $face);
    }

    public function testAttackWithoutDefenseDealsFullDamage(): void
    {
        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Attack), $this->fixed(BitFace::Attack)],
            opponentThrows: [$this->fixed(BitFace::Defense)],
            playerActionTargets: [],
        );

        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testDefenseFullyBlocksEqualAttack(): void
    {
        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Attack)],
            opponentThrows: [$this->fixed(BitFace::Defense)],
            playerActionTargets: [],
        );

        self::assertSame(0, $result->damageToOpponent);
    }

    public function testDamageNeverGoesNegative(): void
    {
        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Defense), $this->fixed(BitFace::Defense)],
            opponentThrows: [$this->fixed(BitFace::Attack)],
            playerActionTargets: [],
        );

        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testActionFaceAloneDealsNoDamage(): void
    {
        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Action)],
            opponentThrows: [$this->fixed(BitFace::Action)],
            playerActionTargets: [],
        );

        self::assertSame(0, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testPlayerActionFlipsTargetedOpponentBit(): void
    {
        $opponentBit = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);

        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Action), $this->fixed(BitFace::Attack)],
            opponentThrows: [$opponentBit],
            playerActionTargets: [0],
        );

        // Opponent's only bit was flipped from "defense" to "attack": it no longer
        // blocks the player's attack, and it now deals damage back.
        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(1, $result->damageToPlayer);
    }

    public function testPlayerActionTargetsAreCappedByRolledActionCount(): void
    {
        $opponentBitA = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);
        $opponentBitB = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);

        $result = $this->resolver->resolveRound(
            // Only one "action" face rolled, but two targets requested.
            playerThrows: [$this->fixed(BitFace::Action), $this->fixed(BitFace::Attack), $this->fixed(BitFace::Attack)],
            opponentThrows: [$opponentBitA, $opponentBitB],
            playerActionTargets: [0, 1],
        );

        // Only the first target (index 0) should have been flipped.
        self::assertSame(BitFace::Attack, $result->opponentFaces[0]);
        self::assertSame(BitFace::Defense, $result->opponentFaces[1]);
    }

    public function testOutOfRangeActionTargetIsIgnored(): void
    {
        $result = $this->resolver->resolveRound(
            playerThrows: [$this->fixed(BitFace::Action)],
            opponentThrows: [$this->fixed(BitFace::Defense)],
            playerActionTargets: [42],
        );

        self::assertSame(BitFace::Defense, $result->opponentFaces[0]);
    }

    public function testBotFlipsPlayerAttackFaceToReduceIncomingDamage(): void
    {
        $playerBit = new BitThrow(BitFace::Attack, BitFace::Action, BitFace::Attack);

        $result = $this->resolver->resolveRound(
            playerThrows: [$playerBit],
            opponentThrows: [$this->fixed(BitFace::Action)],
            playerActionTargets: [],
        );

        self::assertSame(BitFace::Action, $result->playerFaces[0]);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testExplicitOpponentActionTargetsAreUsedInsteadOfBotStrategy(): void
    {
        // BotActionStrategy would flip the "attack" face first (survival
        // priority) — but an explicit PvP opponent choice should override
        // that and flip the "defense" face instead.
        $playerBitA = new BitThrow(BitFace::Attack, BitFace::Defense, BitFace::Attack);
        $playerBitB = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);

        $result = $this->resolver->resolveRound(
            playerThrows: [$playerBitA, $playerBitB],
            opponentThrows: [$this->fixed(BitFace::Action)],
            playerActionTargets: [],
            opponentActionTargets: [1],
        );

        self::assertSame(BitFace::Attack, $result->playerFaces[0]);
        self::assertSame(BitFace::Attack, $result->playerFaces[1]);
    }

    public function testExplicitOpponentActionTargetsAreCappedByRolledActionCount(): void
    {
        $playerBitA = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);
        $playerBitB = new BitThrow(BitFace::Defense, BitFace::Attack, BitFace::Defense);

        $result = $this->resolver->resolveRound(
            playerThrows: [$playerBitA, $playerBitB],
            // Only one "action" face rolled for the opponent, but two targets requested.
            opponentThrows: [$this->fixed(BitFace::Action)],
            playerActionTargets: [],
            opponentActionTargets: [0, 1],
        );

        self::assertSame(BitFace::Attack, $result->playerFaces[0]);
        self::assertSame(BitFace::Defense, $result->playerFaces[1]);
    }

    public function testEmptyOpponentActionTargetsMeansNoFlipsEvenWithActionFacesRolled(): void
    {
        // An explicit empty array (a real PvP player choosing not to flip
        // anything) must NOT fall back to BotActionStrategy.
        $playerBit = new BitThrow(BitFace::Attack, BitFace::Action, BitFace::Attack);

        $result = $this->resolver->resolveRound(
            playerThrows: [$playerBit],
            opponentThrows: [$this->fixed(BitFace::Action)],
            playerActionTargets: [],
            opponentActionTargets: [],
        );

        self::assertSame(BitFace::Attack, $result->playerFaces[0]);
    }
}
