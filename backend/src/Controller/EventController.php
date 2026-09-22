<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Serializer\BattleSerializer;
use App\Service\BattleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events')]
class EventController extends AbstractApiController
{
    public function __construct(private readonly BattleSerializer $serializer)
    {
    }

    #[Route('/active', name: 'event_active', methods: ['GET'])]
    public function active(EventRepository $eventRepository): JsonResponse
    {
        $event = $eventRepository->findCurrentlyActive();

        return $this->json(null === $event ? null : $this->serializeEvent($event));
    }

    #[Route('/active/battle', name: 'event_start_battle', methods: ['POST'])]
    public function startBattle(EventRepository $eventRepository, BattleService $battleService): JsonResponse
    {
        $character = $this->requireCharacter();

        $event = $eventRepository->findCurrentlyActive();
        if (null === $event) {
            return $this->json(['error' => 'No active event right now.'], 404);
        }

        $battle = $battleService->startEventBattle($character, $event);

        return $this->json($this->serializer->battle($battle), 201);
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

    private function serializeEvent(Event $event): array
    {
        return [
            'id' => $event->getId(),
            'type' => $event->getType(),
            'monsterName' => $event->getMonsterName(),
            'monsterHp' => $event->getMonsterHp(),
            'endsAt' => $event->getEndsAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
