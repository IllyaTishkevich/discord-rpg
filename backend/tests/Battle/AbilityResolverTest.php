<?php

namespace App\Tests\Battle;

use App\Battle\AbilityChoice;
use App\Battle\AbilityResolver;
use App\Battle\BitThrow;
use App\Battle\RoundResult;
use App\Enum\AbilityType;
use App\Enum\BitFace;
use App\Exception\InsufficientActionPointsException;
use PHPUnit\Framework\TestCase;

class AbilityResolverTest extends TestCase
{
    private AbilityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AbilityResolver();
    }

    private function fixed(BitFace $face): BitThrow
    {
        return new BitThrow($face, $face, false, false, $face, false);
    }

    // --- assertAffordable ---

    public function testRerollRequiresOneActionPoint(): void
    {
        $this->expectException(InsufficientActionPointsException::class);
        $this->resolver->assertAffordable(new AbilityChoice(AbilityType::Reroll), 0);
    }

    public function testRerollIsAffordableWithOneActionPoint(): void
    {
        $this->resolver->assertAffordable(new AbilityChoice(AbilityType::Reroll), 1);
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testDamageMirrorRequiresTwoActionPoints(): void
    {
        $this->expectException(InsufficientActionPointsException::class);
        $this->resolver->assertAffordable(new AbilityChoice(AbilityType::DamageMirror), 1);
    }

    public function testDamageMirrorIsAffordableWithTwoActionPoints(): void
    {
        $this->resolver->assertAffordable(new AbilityChoice(AbilityType::DamageMirror), 2);
        $this->addToAssertionCount(1);
    }

    public function testFlipAndUnblockableDamageHaveNoFixedCost(): void
    {
        $this->resolver->assertAffordable(AbilityChoice::flip(), 0);
        $this->resolver->assertAffordable(new AbilityChoice(AbilityType::UnblockableDamage), 0);
        $this->addToAssertionCount(2);
    }

    // --- effectiveFlipTargets ---

    public function testOnlyFlipProducesFlipTargets(): void
    {
        self::assertSame([0, 1], $this->resolver->effectiveFlipTargets(AbilityChoice::flip([0, 1])));
        self::assertSame([], $this->resolver->effectiveFlipTargets(new AbilityChoice(AbilityType::Reroll)));
        self::assertSame([], $this->resolver->effectiveFlipTargets(new AbilityChoice(AbilityType::UnblockableDamage)));
        self::assertSame([], $this->resolver->effectiveFlipTargets(new AbilityChoice(AbilityType::DamageMirror, [0, 1])));
    }

    // --- applyPreDamage (Reroll) ---

    public function testNonRerollLeavesThrowsUntouched(): void
    {
        $throws = [$this->fixed(BitFace::Attack), $this->fixed(BitFace::Defense)];

        $result = $this->resolver->applyPreDamage($throws, AbilityChoice::flip());

        self::assertSame($throws, $result);
    }

    public function testRerollReRandomizesEachBitFromItsOwnFaces(): void
    {
        // Both faces of this bit are the same value, so a reroll is
        // deterministic — useful to prove it re-derives from faceA/faceB
        // (not just leaving the original throw in place).
        $throws = [new BitThrow(BitFace::Defense, BitFace::Defense, false, false, BitFace::Attack, false)];

        $result = $this->resolver->applyPreDamage($throws, new AbilityChoice(AbilityType::Reroll));

        self::assertSame(BitFace::Defense, $result[0]->thrownFace);
        self::assertSame(BitFace::Defense, $result[0]->faceA);
        self::assertSame(BitFace::Defense, $result[0]->faceB);
    }

    // --- applyPostDamage ---

    public function testUnblockableDamageAddsFlatDamageToOpponent(): void
    {
        $base = new RoundResult([], [], damageToOpponent: 1, damageToPlayer: 0);

        $result = $this->resolver->applyPostDamage(
            $base,
            new AbilityChoice(AbilityType::UnblockableDamage),
            AbilityChoice::flip(),
            characterActionCount: 3,
            opponentActionCount: 0,
        );

        self::assertSame(4, $result->damageToOpponent);
        self::assertSame(0, $result->damageToPlayer);
    }

    public function testDamageMirrorAddsPlayersOwnDamageTakenOntoOpponent(): void
    {
        $base = new RoundResult([], [], damageToOpponent: 1, damageToPlayer: 2);

        $result = $this->resolver->applyPostDamage(
            $base,
            new AbilityChoice(AbilityType::DamageMirror),
            AbilityChoice::flip(),
            characterActionCount: 2,
            opponentActionCount: 0,
        );

        // 1 (original) + 2 (mirrored from what the character itself took)
        self::assertSame(3, $result->damageToOpponent);
        self::assertSame(2, $result->damageToPlayer);
    }

    public function testSimultaneousDamageMirrorOnBothSidesDoesNotFeedBackOnItself(): void
    {
        $base = new RoundResult([], [], damageToOpponent: 1, damageToPlayer: 2);

        $result = $this->resolver->applyPostDamage(
            $base,
            new AbilityChoice(AbilityType::DamageMirror),
            new AbilityChoice(AbilityType::DamageMirror),
            characterActionCount: 2,
            opponentActionCount: 2,
        );

        // Each side's mirror reacts to the ORIGINAL opposing damage value
        // (1 and 2), not to a value already inflated by the other side's mirror.
        self::assertSame(1 + 2, $result->damageToOpponent);
        self::assertSame(2 + 1, $result->damageToPlayer);
    }

    public function testFlipChoiceLeavesDamageUnchanged(): void
    {
        $base = new RoundResult([], [], damageToOpponent: 1, damageToPlayer: 2);

        $result = $this->resolver->applyPostDamage($base, AbilityChoice::flip(), AbilityChoice::flip(), 3, 3);

        self::assertSame(1, $result->damageToOpponent);
        self::assertSame(2, $result->damageToPlayer);
    }

    // --- countActionFaces ---

    public function testCountActionFaces(): void
    {
        $throws = [$this->fixed(BitFace::Action), $this->fixed(BitFace::Attack), $this->fixed(BitFace::Action)];

        self::assertSame(2, $this->resolver->countActionFaces($throws));
    }
}
