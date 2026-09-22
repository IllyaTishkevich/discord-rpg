<?php

namespace App\Repository;

use App\Entity\Event;
use App\Enum\EventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return Event[]
     */
    public function findAllMarkedActive(): array
    {
        return $this->findBy(['status' => EventStatus::Active]);
    }

    public function findCurrentlyActive(): ?Event
    {
        foreach ($this->findAllMarkedActive() as $event) {
            if ($event->isActiveNow()) {
                return $event;
            }
        }

        return null;
    }
}
