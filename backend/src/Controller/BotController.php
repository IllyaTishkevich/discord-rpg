<?php

namespace App\Controller;

use App\Battle\AbilityChoice;
use App\Entity\Battle;
use App\Enum\BattleStatus;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Repository\WeeklyQuestRepository;
use App\Serializer\BattleSerializer;
use App\Service\BattleService;
use App\Service\EventService;
use App\Service\QuestService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/bot')]
class BotController extends AbstractBotController
{
    // Safety cap: with the current 3-bit starter loadouts, a round almost
    // always deals damage on one side, but 0-0 draws are possible, so an
    // unbounded auto-play loop isn't safe for a synchronous HTTP request.
    private const MAX_AUTO_ROUNDS = 30;

    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] string $botApiSecret,
        private readonly UserRepository $userRepository,
        private readonly BattleSerializer $serializer,
    ) {
        parent::__construct($botApiSecret);
    }

    #[Route('/characters/{discordId}', name: 'bot_character_profile', methods: ['GET'])]
    public function profile(string $discordId, Request $request): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        return $this->json([
            'class' => [
                'code' => $character->getCharacterClass()->getCode(),
                'name' => $character->getCharacterClass()->getName(),
            ],
            'hp' => $character->getHp(),
            'maxHp' => $character->getMaxHp(),
            'energy' => $character->getEnergy(),
            'maxEnergy' => $character->getMaxEnergy(),
            'level' => $character->getLevel(),
            'xp' => $character->getXp(),
            'coins' => $character->getCoins(),
        ]);
    }

    #[Route('/quests/{discordId}', name: 'bot_quest_current', methods: ['GET'])]
    public function quest(string $discordId, Request $request, WeeklyQuestRepository $weeklyQuestRepository, QuestService $questService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        $quest = $weeklyQuestRepository->findCurrent();
        if (null === $quest) {
            return $this->json(null);
        }

        $progress = $questService->getOrCreateProgress($character, $quest);

        return $this->json([
            'targetValue' => $quest->getTargetValue(),
            'rewardXp' => $quest->getRewardXp(),
            'rewardCoins' => $quest->getRewardCoins(),
            'progress' => $progress->getProgress(),
            'isComplete' => $progress->isComplete(),
            'isClaimed' => $progress->isClaimed(),
        ]);
    }

    /**
     * Plays a full PvE battle to completion with no action-flip choices —
     * the text-only bot command has no per-round UI, unlike the Activity's
     * interactive arena. Spends one energy point just like the Activity flow.
     */
    #[Route('/battles/{discordId}/pve/auto', name: 'bot_battle_auto_pve', methods: ['POST'])]
    public function autoPve(string $discordId, Request $request, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        try {
            $battle = $battleService->startPveBattle($character);
        } catch (InsufficientEnergyException) {
            return $this->json(['error' => 'Not enough energy to start a battle.'], 409);
        }

        $roundsPlayed = $this->playToCompletion($battle, $battleService);

        return $this->json(['roundsPlayed' => $roundsPlayed, ...$this->serializer->battle($battle)]);
    }

    /**
     * Admin-triggered: starts a new "monster attack" server event. The bot
     * command calling this is expected to be permission-gated on the Discord
     * side (e.g. ManageGuild) since there's no admin-role system here.
     */
    #[Route('/events/monster-attack', name: 'bot_event_start_monster_attack', methods: ['POST'])]
    public function startMonsterAttackEvent(Request $request, EventService $eventService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $event = $eventService->startMonsterAttack();

        return $this->json([
            'monsterName' => $event->getMonsterName(),
            'monsterHp' => $event->getMonsterHp(),
            'endsAt' => $event->getEndsAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    /**
     * Text-only equivalent of the Activity's event arena: plays the current
     * event battle to completion with no action-flip choices.
     */
    #[Route('/events/active/battle/auto/{discordId}', name: 'bot_event_battle_auto', methods: ['POST'])]
    public function autoEventBattle(string $discordId, Request $request, EventRepository $eventRepository, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        $event = $eventRepository->findCurrentlyActive();
        if (null === $event) {
            return $this->json(['error' => 'No active event right now.'], 404);
        }

        $battle = $battleService->startEventBattle($character, $event);
        $roundsPlayed = $this->playToCompletion($battle, $battleService);

        return $this->json(['roundsPlayed' => $roundsPlayed, ...$this->serializer->battle($battle)]);
    }

    private function playToCompletion(Battle $battle, BattleService $battleService): int
    {
        $roundsPlayed = 0;
        for (; $roundsPlayed < self::MAX_AUTO_ROUNDS && BattleStatus::InProgress === $battle->getStatus(); ++$roundsPlayed) {
            $battleService->throwRound($battle);

            try {
                $battleService->resolveRound($battle, AbilityChoice::flip());
            } catch (BattleAlreadyFinishedException) {
                break;
            }
        }

        return $roundsPlayed;
    }
}
