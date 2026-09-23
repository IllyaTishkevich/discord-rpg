<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AbilityCrudController;
use App\Controller\Admin\AdminCrudController;
use App\Controller\Admin\BattleCrudController;
use App\Controller\Admin\BattleRoundCrudController;
use App\Controller\Admin\BitCrudController;
use App\Controller\Admin\CharacterClassCrudController;
use App\Controller\Admin\CharacterCrudController;
use App\Controller\Admin\CharacterEquipmentCrudController;
use App\Controller\Admin\CharacterQuestProgressCrudController;
use App\Controller\Admin\EquipmentCrudController;
use App\Controller\Admin\EventCrudController;
use App\Controller\Admin\MonsterCrudController;
use App\Controller\Admin\TournamentCrudController;
use App\Controller\Admin\TournamentEntryCrudController;
use App\Controller\Admin\TournamentMatchCrudController;
use App\Controller\Admin\UserCrudController;
use App\Controller\Admin\WeeklyQuestCrudController;
use App\Entity\Admin;
use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\Equipment;
use App\Entity\User;
use App\Enum\BitFace;
use App\Enum\EquipmentEffectType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Google OAuth can't be exercised in tests without real credentials, so
 * these log an Admin in directly (bypassing the OAuth handshake, not the
 * ROLE_ADMIN access_control) and drive the actual EasyAdmin controllers —
 * exactly the layer where earlier manual review missed real bugs (routes
 * not under /admin, "New" crashing on entities whose constructor requires
 * args since EasyAdmin calls `new $fqcn()` directly).
 */
class AdminPanelTest extends WebTestCase
{
    /** @var class-string[] */
    private const ALL_CRUD_CONTROLLERS = [
        UserCrudController::class,
        CharacterCrudController::class,
        CharacterClassCrudController::class,
        BitCrudController::class,
        AbilityCrudController::class,
        EquipmentCrudController::class,
        CharacterEquipmentCrudController::class,
        BattleCrudController::class,
        BattleRoundCrudController::class,
        MonsterCrudController::class,
        EventCrudController::class,
        TournamentCrudController::class,
        TournamentEntryCrudController::class,
        TournamentMatchCrudController::class,
        WeeklyQuestCrudController::class,
        CharacterQuestProgressCrudController::class,
        AdminCrudController::class,
    ];

    /** @var class-string[] New-enabled controllers (createEntity() overrides supply constructor args) */
    private const NEW_ENABLED_CONTROLLERS = [
        CharacterClassCrudController::class,
        BitCrudController::class,
        AbilityCrudController::class,
        EquipmentCrudController::class,
        MonsterCrudController::class,
    ];

    public function testAnonymousIsRedirectedAwayFromAdmin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects();
    }

    public function testLoggedInAdminCanViewEveryCrudIndexPage(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);

        foreach (self::ALL_CRUD_CONTROLLERS as $controllerFqcn) {
            $url = $urlGenerator->setController($controllerFqcn)->setAction('index')->generateUrl();
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('Index page for %s should load without error', $controllerFqcn));
        }
    }

    public function testNewEnabledCrudsRenderTheirCreateForm(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->ensureCharacterExists(); // BitCrudController::createEntity() needs one to placeholder-construct
        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);

        foreach (self::NEW_ENABLED_CONTROLLERS as $controllerFqcn) {
            $url = $urlGenerator->setController($controllerFqcn)->setAction('new')->generateUrl();
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('"New" form for %s should render without error', $controllerFqcn));
        }
    }

    public function testUserCrudNewActionIsDisabled(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);

        $url = $urlGenerator->setController(UserCrudController::class)->setAction('new')->generateUrl();
        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(403, 'The "New" action is disabled for User (NoCreateCrudTrait) and must be blocked, not crash');
    }

    public function testCreatingEquipmentThroughTheAdminFormPersistsIt(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);

        $newUrl = $urlGenerator->setController(EquipmentCrudController::class)->setAction('new')->generateUrl();
        $crawler = $client->request('GET', $newUrl);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Equipment"]')->form();
        $form['Equipment[code]'] = 'admin_test_item';
        $form['Equipment[name]'] = 'Admin Test Item';
        $form['Equipment[price]'] = '15';
        $form['Equipment[effectType]'] = EquipmentEffectType::Hp->value;
        $form['Equipment[hpBonus]'] = '3';

        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get('doctrine')->getManager();
        $created = $em->getRepository(Equipment::class)->findOneBy(['code' => 'admin_test_item']);
        self::assertNotNull($created, 'Equipment created via the admin form should be persisted');
        self::assertSame(15, $created->getPrice());

        $em->remove($created);
        $em->flush();
    }

    public function testAnonymousCannotAccessActivityPreview(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/activity-preview');

        self::assertResponseRedirects();
    }

    public function testLoggedInAdminCanViewActivityPreviewIndex(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->ensureCharacterExists();

        $client->request('GET', '/admin/activity-preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/admin/activity-preview/launch/"]');
    }

    /**
     * The whole point of this tool is minting a token that actually works —
     * not just that some string ends up in the rendered iframe's src.
     */
    public function testActivityPreviewLaunchMintsAWorkingJwt(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->ensureCharacterExists();

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['discordId' => 'test-fixture-discord-id']);
        self::assertNotNull($user);

        $crawler = $client->request('GET', \sprintf('/admin/activity-preview/launch/%d', $user->getId()));
        self::assertResponseIsSuccessful();

        $iframeSrc = $crawler->filter('iframe')->attr('src');
        self::assertNotNull($iframeSrc);

        parse_str((string) parse_url($iframeSrc, \PHP_URL_QUERY), $query);
        self::assertArrayHasKey('devToken', $query);

        // Same client, different (stateless, JWT-based) firewall — a test
        // can only boot one kernel/client, but /api doesn't care about the
        // admin session cookie either way.
        $client->request('GET', '/api/characters/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$query['devToken']]);
        self::assertResponseIsSuccessful();
    }

    private function loginAsAdmin($client): Admin
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $repository = $em->getRepository(Admin::class);

        $admin = $repository->findOneBy(['email' => 'test-admin@example.com']);
        if (null === $admin) {
            $admin = new Admin('test-admin@example.com', 'Test Admin');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');

        return $admin;
    }

    private function ensureCharacterExists(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        if (null !== $em->getRepository(Character::class)->findOneBy([])) {
            return;
        }

        $class = $em->getRepository(CharacterClass::class)->findOneBy(['code' => 'test_fixture_class']);
        if (null === $class) {
            $class = new CharacterClass('test_fixture_class', 'Fixture Class', 20, 10);
            $em->persist($class);

            $templateBit = new Bit(BitFace::Attack, BitFace::Defense);
            $em->persist($templateBit);
            $class->addStarterBit($templateBit);
        }

        $user = new User('test-fixture-discord-id', 'Fixture Player');
        $character = new Character($user, $class);
        $user->setCharacter($character);
        $em->persist($user);
        $em->persist($character);
        $em->flush();
    }
}
