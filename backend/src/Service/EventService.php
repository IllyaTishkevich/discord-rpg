<?php

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

class EventService
{
    private const MONSTER_ATTACK_MONSTER_NAME = 'Орда монстров';
    private const MONSTER_ATTACK_MONSTER_HP = 30;
    private const MONSTER_ATTACK_DURATION = 'PT1H';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository,
    ) {
    }

    public function startMonsterAttack(): Event
    {
        foreach ($this->eventRepository->findAllMarkedActive() as $activeEvent) {
            $activeEvent->end();
        }

        $event = new Event(
            'monster_attack',
            self::MONSTER_ATTACK_MONSTER_NAME,
            self::MONSTER_ATTACK_MONSTER_HP,
            new \DateInterval(self::MONSTER_ATTACK_DURATION),
        );
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }
}
