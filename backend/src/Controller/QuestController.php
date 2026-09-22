<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Exception\QuestAlreadyClaimedException;
use App\Exception\QuestNotCompleteException;
use App\Repository\WeeklyQuestRepository;
use App\Service\QuestService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/quests')]
class QuestController extends AbstractApiController
{
    #[Route('/current', name: 'quest_current', methods: ['GET'])]
    public function current(WeeklyQuestRepository $weeklyQuestRepository, QuestService $questService): JsonResponse
    {
        $character = $this->requireCharacter();

        $quest = $weeklyQuestRepository->findCurrent();
        if (null === $quest) {
            return $this->json(null);
        }

        $progress = $questService->getOrCreateProgress($character, $quest);

        return $this->json([
            'weekStart' => $quest->getWeekStart()->format('Y-m-d'),
            'type' => $quest->getType(),
            'targetValue' => $quest->getTargetValue(),
            'rewardXp' => $quest->getRewardXp(),
            'rewardCoins' => $quest->getRewardCoins(),
            'progress' => $progress->getProgress(),
            'isComplete' => $progress->isComplete(),
            'isClaimed' => $progress->isClaimed(),
        ]);
    }

    #[Route('/current/claim', name: 'quest_claim', methods: ['POST'])]
    public function claim(WeeklyQuestRepository $weeklyQuestRepository, QuestService $questService): JsonResponse
    {
        $character = $this->requireCharacter();

        $quest = $weeklyQuestRepository->findCurrent();
        if (null === $quest) {
            return $this->json(['error' => 'No active quest.'], 404);
        }

        try {
            $questService->claim($character, $quest);
        } catch (QuestNotCompleteException) {
            return $this->json(['error' => 'Quest is not complete yet.'], 409);
        } catch (QuestAlreadyClaimedException) {
            return $this->json(['error' => 'Reward already claimed.'], 409);
        }

        return $this->json([
            'xp' => $character->getXp(),
            'coins' => $character->getCoins(),
        ]);
    }

    private function requireCharacter(): Character
    {
        /** @var User $user */
        $user = $this->getUser();
        $character = $user->getCharacter();
        if (null === $character) {
            throw $this->createNotFoundException('No character for this user yet.');
        }

        return $character;
    }
}
