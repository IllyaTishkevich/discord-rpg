<?php

namespace App\Tests\Service;

use App\Battle\AbilityResolver;
use App\Battle\ExchangeResolver;
use App\Battle\InteractiveExchangeEngine;
use App\Entity\Battle;
use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\Monster;
use App\Entity\User;
use App\Enum\BitFace;
use App\Repository\MonsterRepository;
use App\Service\BattleService;
use App\Service\InventoryService;
use App\Service\LootService;
use App\Service\QuestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;

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
            mercureHub: $this->createMock(HubInterface::class),
        );
    }

    private function makePveBattle(Bit $playerBit, Bit $monsterBit): Battle
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', 30, 10);
        $class->addStarterBit($playerBit);
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
        // to lead first (nothing auto-played by throwRound()).
        $battle = $this->makePveBattle(
            $this->bit(BitFace::Action, advantage: true),
            $this->bit(BitFace::Attack),
        );

        $service->throwRound($battle);
        self::assertSame(30, $battle->getCharacter()->getHp());

        // 1st timeout: the player's own lead is forfeited to the bot, which
        // leads with its Attack bit — the player still has its Action bit
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
        // to lead first (nothing auto-played by throwRound()).
        $battle = $this->makePveBattle(
            $this->bit(BitFace::Action, advantage: true),
            $this->bit(BitFace::Attack),
        );

        $service->throwRound($battle);
        self::assertSame(30, $battle->getCharacter()->getHp());

        // Explicit pass (the Activity's "Пропустить ход" button, submitted
        // as an empty indices array while it's a lead turn) — hands the
        // lead to the bot immediately, same as a timed-out lead, just
        // without waiting for the deadline. The bot leads with its Attack
        // bit; the player still has its Action bit to respond with, so
        // this pauses rather than dealing damage yet.
        $result = $service->submitExchangeMove($battle, $battle->getCharacter(), [], null);

        self::assertSame(30, $battle->getCharacter()->getHp(), 'voluntarily passing the lead must not deal damage by itself');
        self::assertFalse($result->roundComplete);
        self::assertSame('respond', $result->turn);
        self::assertSame([], $result->newExchanges, 'passing the lead resolves no exchange of its own — only the bot\'s subsequent lead pauses at respond');
    }
}
