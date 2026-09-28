<?php

namespace App\Tests\Service;

use App\Battle\AbilityResolver;
use App\Battle\ExchangeResolver;
use App\Battle\InteractiveExchangeEngine;
use App\Entity\Ability;
use App\Entity\Battle;
use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\Monster;
use App\Entity\User;
use App\Enum\AbilityType;
use App\Enum\BitFace;
use App\Repository\MonsterRepository;
use App\Service\BattleService;
use App\Service\InventoryService;
use App\Service\LootService;
use App\Service\QuestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Focused on the per-move turn timer (BattleService::applyMoveTimeoutIfExpired()/
 * refreshMoveDeadline()) — specifically that it now covers PvE too, not just
 * PvP (which already had full coverage of its own logic elsewhere and is
 * untouched by this generalization). Bits are constructed with faceA===faceB
 * so BitThrow::random() (used internally by throwRound(), not directly
 * injectable) always shows a known face — the same trick
 * ExchangeResolverTest/InteractiveExchangeEngineTest use for their own
 * `bit()` helpers.
 */
class BattleServiceTest extends TestCase
{
    private function bit(BitFace $face, bool $advantage = false, int $multiplier = 1): Bit
    {
        return new Bit($face, $face, $advantage, $advantage, $multiplier, $multiplier);
    }

    private function makeBattleService(EntityManagerInterface $entityManager): BattleService
    {
        return new BattleService(
            $entityManager,
            new ExchangeResolver(),
            new InteractiveExchangeEngine(),
            new AbilityResolver(),
            $this->createMock(QuestService::class),
            $this->createMock(MonsterRepository::class),
            new LootService($entityManager, new InventoryService($entityManager)),
            roundTimeoutSeconds: 15,
            // Never actually called — every publishPvpUpdate() call site is
            // gated behind isPvp(), and every scenario here is PvE (see class
            // docblock).
            httpClient: $this->createMock(HttpClientInterface::class),
            botInternalUrl: 'http://example.test',
            botApiSecret: 'test-secret',
        );
    }

    /**
     * @param Bit|Bit[] $playerBits
     */
    private function makePveBattle(Bit|array $playerBits, Bit $monsterBit): Battle
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', 30, 10);
        foreach (\is_array($playerBits) ? $playerBits : [$playerBits] as $playerBit) {
            $class->addStarterBit($playerBit);
        }
        $character = new Character($user, $class);

        $monster = new Monster('Test Monster', 1, 999);
        $monster->addBit($monsterBit);

        $battle = new Battle($character, $monster->getName(), $monster->getMaxHp());
        $battle->setOpponentMonster($monster);

        return $battle;
    }

    public function testExpiredResponseDeadlineAppliesFullDamageInPve(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');
        $service = $this->makeBattleService($entityManager);

        // Monster has the advantage, so it leads first — the player has a
        // Defense bit to respond with, so this pauses (respond) rather than
        // resolving inline (see InteractiveExchangeEngine::autoAdvance()).
        $battle = $this->makePveBattle(
            $this->bit(BitFace::Defense),
            $this->bit(BitFace::Attack, advantage: true, multiplier: 3),
        );

        $service->throwRound($battle);
        self::assertSame(30, $battle->getCharacter()->getHp());

        $battle->setRoundDeadlineAt(new \DateTimeImmutable('-1 second'));
        $service->syncExchangeState($battle);

        self::assertSame(27, $battle->getCharacter()->getHp(), 'an expired respond deadline must take full (×3) damage, same as an explicit pass');
    }

    public function testExpiredLeadDeadlineHandsTheLeadToTheBotWhichThenGetsATimeoutOfItsOwn(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');
        $service = $this->makeBattleService($entityManager);

        // Player has the advantage, so it's genuinely the player's own turn
        // to lead first (nothing auto-played by throwRound()). The Defense
        // bit is what actually lets the pause below happen — a response may
        // only ever be a defense bit (docs/COMBAT_V2_DESIGN.md §3/§4), so
        // the Action bit alone would never qualify.
        $battle = $this->makePveBattle(
            [$this->bit(BitFace::Action, advantage: true), $this->bit(BitFace::Defense)],
            $this->bit(BitFace::Attack),
        );

        $service->throwRound($battle);
        self::assertSame(30, $battle->getCharacter()->getHp());

        // 1st timeout: the player's own lead is forfeited to the bot, which
        // leads with its Attack bit — the player still has its Defense bit
        // to respond with, so this pauses rather than dealing damage yet.
        $battle->setRoundDeadlineAt(new \DateTimeImmutable('-1 second'));
        $service->syncExchangeState($battle);
        self::assertSame(30, $battle->getCharacter()->getHp(), 'forfeiting the lead must not deal damage by itself');

        // 2nd timeout: now it's a respond deadline (to the bot's Attack) —
        // expires too, taking full damage.
        $battle->setRoundDeadlineAt(new \DateTimeImmutable('-1 second'));
        $service->syncExchangeState($battle);
        self::assertSame(29, $battle->getCharacter()->getHp());
    }

    public function testVoluntarilyPassingTheLeadInPveHandsItToTheBotWithoutConsumingBits(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');
        $service = $this->makeBattleService($entityManager);

        // Player has the advantage, so it's genuinely the player's own turn
        // to lead first (nothing auto-played by throwRound()). The Defense
        // bit is what actually lets the pause below happen — a response may
        // only ever be a defense bit (docs/COMBAT_V2_DESIGN.md §3/§4), so
        // the Action bit alone would never qualify.
        $battle = $this->makePveBattle(
            [$this->bit(BitFace::Action, advantage: true), $this->bit(BitFace::Defense)],
            $this->bit(BitFace::Attack),
        );

        $service->throwRound($battle);
        self::assertSame(30, $battle->getCharacter()->getHp());

        // Explicit pass (the Activity's "Пропустить ход" button, submitted
        // as an empty indices array while it's a lead turn) — hands the
        // lead to the bot immediately, same as a timed-out lead, just
        // without waiting for the deadline. The bot leads with its Attack
        // bit; the player still has its Defense bit to respond with, so
        // this pauses rather than dealing damage yet.
        $result = $service->submitExchangeMove($battle, $battle->getCharacter(), [], null);

        self::assertSame(30, $battle->getCharacter()->getHp(), 'voluntarily passing the lead must not deal damage by itself');
        self::assertFalse($result->roundComplete);
        self::assertSame('respond', $result->turn);
        self::assertSame([], $result->newExchanges, 'passing the lead resolves no exchange of its own — only the bot\'s subsequent lead pauses at respond');
    }

    public function testALoneActionBitThatCantAffordAnyOwnedAbilityIsTreatedAsDeadAndEndsTheRoundAutomatically(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');
        $service = $this->makeBattleService($entityManager);

        // Monster's Defense bit has the advantage, so it leads first —
        // resolves unopposed (the player has no Defense bit to respond
        // with), handing the lead to the player. The player's only bit is
        // then a single Action bit — but this fixture's character owns no
        // abilities at all (makePveBattle() never grants any), so no
        // amount of action points could ever afford one: it must be
        // treated as dead (same as an Empty face), letting the round end
        // right here via syncExchangeState() instead of forcing the player
        // through a pointless ability picker.
        $battle = $this->makePveBattle(
            $this->bit(BitFace::Action),
            $this->bit(BitFace::Defense, advantage: true),
        );

        $service->throwRound($battle);
        self::assertTrue($battle->hasPendingThrow(), 'still pending right after the throw — syncExchangeState() is what finalizes it');

        $service->syncExchangeState($battle);

        self::assertFalse($battle->hasPendingThrow(), 'a lone action bit that can never afford any owned ability must not keep the round going');
    }

    public function testALoneActionBitStillCountsWhenTheCharacterOwnsFlip(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');
        $service = $this->makeBattleService($entityManager);

        // Same shape as the "dead" test above, except this character
        // actually owns Flip — always triggerable regardless of amount
        // (even with 0 targets), so the lone action bit must NOT be
        // treated as dead: the round keeps going and it becomes the
        // player's genuine turn to lead with it.
        $battle = $this->makePveBattle(
            $this->bit(BitFace::Action),
            $this->bit(BitFace::Defense, advantage: true),
        );
        $battle->getCharacter()->addAbility(new Ability(AbilityType::Flip));

        $service->throwRound($battle);
        $service->syncExchangeState($battle);

        self::assertTrue($battle->hasPendingThrow(), 'a lone action bit must still count when the character owns an ability that can use it');
    }
}
