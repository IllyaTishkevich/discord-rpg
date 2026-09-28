<?php

namespace App\Tests\Controller;

use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterEquipment;
use App\Entity\CharacterQuestProgress;
use App\Entity\Equipment;
use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use App\Entity\TournamentMatch;
use App\Entity\User;
use App\Entity\WeeklyQuest;
use App\Enum\EquipmentEffectType;
use App\Enum\TournamentStatus;
use App\Repository\BattleRepository;
use App\Repository\CharacterEquipmentRepository;
use App\Repository\CharacterQuestProgressRepository;
use App\Repository\TournamentEntryRepository;
use App\Repository\TournamentMatchRepository;
use App\Repository\TournamentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for CharacterService::wipeCharacter(): most tables
 * that reference character.id have a plain (NO ACTION) foreign key, not
 * ON DELETE CASCADE — see the migrations — so a naive remove() on just the
 * Character row throws a foreign key violation the moment it has ever
 * fought a battle, joined a tournament, bought equipment, or made quest
 * progress. This builds one of each and wipes through the real bot
 * endpoint against the real test database, where a missed cleanup step
 * would surface as an actual DB error, not a mocked-away no-op.
 */
class BotControllerWipeTest extends WebTestCase
{
    public function testWipingACharacterWithFullHistoryDeletesEverythingWithoutForeignKeyErrors(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('wipe_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);

        $discordId = 'wipe-'.uniqid();
        $user = new User($discordId, 'Wipe Target');
        $character = new Character($user, $class);
        $user->setCharacter($character);
        $em->persist($user);
        $em->persist($character);

        $opponentUser = new User('wipe-opp-'.uniqid(), 'Wipe Opponent');
        $opponent = new Character($opponentUser, $class);
        $opponentUser->setCharacter($opponent);
        $em->persist($opponentUser);
        $em->persist($opponent);
        $em->flush();

        // A finished PvP battle, on both the character and opponentCharacter
        // FK columns, with a round — Battle::$rounds' orphanRemoval must
        // cascade to BattleRound, whose own FK to battle has no DB cascade.
        $battle = Battle::createPvp($character, $opponent);
        $em->persist($battle);
        $em->flush();
        $round = new BattleRound($battle, 1, ['attack'], ['defense'], 3, 0);
        $em->persist($round);

        // A tournament this character both entered and won.
        $tournament = new Tournament();
        $tournament->finish($character);
        $em->persist($tournament);
        $entry = new TournamentEntry($tournament, $character);
        $em->persist($entry);
        $match = new TournamentMatch($tournament, 1, 0, $character, $opponent, $character);
        $em->persist($match);

        // weekStart is unique across all WeeklyQuest rows — pick an
        // arbitrary date nothing else in the suite could plausibly use,
        // since this fixture only cares about progress FK cleanup, not
        // the quest's own scheduling.
        $quest = new WeeklyQuest(new \DateTimeImmutable('-'.random_int(1000, 100000).' days'), 'win_battles', 5, 10, 20);
        $em->persist($quest);
        $progress = new CharacterQuestProgress($character, $quest);
        $em->persist($progress);

        $equipment = new Equipment('wipe_test_gear_'.uniqid(), 'Fixture Gear', 100, EquipmentEffectType::Hp);
        $em->persist($equipment);
        $ownedEquipment = new CharacterEquipment($character, $equipment);
        $em->persist($ownedEquipment);

        $em->flush();
        $characterId = $character->getId();
        $tournamentId = $tournament->getId();

        $client->request('DELETE', "/api/bot/characters/{$discordId}", [], [], [
            'HTTP_X-Bot-Secret' => 'changeme-bot-secret',
        ]);

        self::assertResponseStatusCodeSame(204);

        $em->clear();

        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepository->findOneByDiscordId($discordId)?->getCharacter(), 'the user must be free to create a new character');

        self::assertNull($em->find(Character::class, $characterId));

        $survivingTournament = $em->find(Tournament::class, $tournamentId);
        self::assertNotNull($survivingTournament, 'a tournament the character won must survive the wipe');
        self::assertNull($survivingTournament->getChampionCharacter(), 'but it must forget the deleted champion');

        // Rebuild a Character reference purely to query "what still points at
        // this id" — the real row is gone, but Doctrine's find-by-criteria
        // methods below only need a proxy with the right id for the FK
        // comparison, never dereferencing the deleted row itself.
        $ghostCharacter = $em->getReference(Character::class, $characterId);

        self::assertSame([], static::getContainer()->get(BattleRepository::class)->findAllFor($ghostCharacter));
        self::assertSame([], static::getContainer()->get(TournamentMatchRepository::class)->findAllFor($ghostCharacter));
        self::assertSame([], static::getContainer()->get(TournamentEntryRepository::class)->findByCharacter($ghostCharacter));
        self::assertSame([], static::getContainer()->get(CharacterQuestProgressRepository::class)->findByCharacter($ghostCharacter));
        self::assertSame([], static::getContainer()->get(CharacterEquipmentRepository::class)->findByCharacter($ghostCharacter));
        self::assertSame([], static::getContainer()->get(TournamentRepository::class)->findAllByChampionCharacter($ghostCharacter));
    }

    public function testWipingWithoutACharacterReturns404(): void
    {
        $client = static::createClient();

        $client->request('DELETE', '/api/bot/characters/no-such-discord-id', [], [], [
            'HTTP_X-Bot-Secret' => 'changeme-bot-secret',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWipingWithoutTheBotSecretIsForbidden(): void
    {
        $client = static::createClient();

        $client->request('DELETE', '/api/bot/characters/whatever');

        self::assertResponseStatusCodeSame(403);
    }
}
