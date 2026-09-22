<?php

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterQuestProgress;
use App\Entity\WeeklyQuest;
use App\Exception\QuestAlreadyClaimedException;
use App\Exception\QuestNotCompleteException;
use App\Repository\CharacterQuestProgressRepository;
use App\Repository\WeeklyQuestRepository;
use Doctrine\ORM\EntityManagerInterface;

class QuestService
{
    private const DEFAULT_TYPE = 'win_battles';
    private const DEFAULT_TARGET = 5;
    private const DEFAULT_REWARD_XP = 30;
    private const DEFAULT_REWARD_COINS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WeeklyQuestRepository $weeklyQuestRepository,
        private readonly CharacterQuestProgressRepository $progressRepository,
    ) {
    }

    /**
     * Idempotent: safe to call repeatedly (e.g. from a cron-triggered
     * console command) — returns the existing quest if this week already has one.
     */
    public function generateForCurrentWeek(): WeeklyQuest
    {
        $weekStart = WeeklyQuestRepository::currentWeekStart();
        $existing = $this->weeklyQuestRepository->findOneByWeekStart($weekStart);
        if (null !== $existing) {
            return $existing;
        }

        $quest = new WeeklyQuest($weekStart, self::DEFAULT_TYPE, self::DEFAULT_TARGET, self::DEFAULT_REWARD_XP, self::DEFAULT_REWARD_COINS);
        $this->entityManager->persist($quest);
        $this->entityManager->flush();

        return $quest;
    }

    /**
     * Call whenever a character wins a (non-simulated) battle — advances
     * their progress on the current week's "win_battles" quest, if any.
     */
    public function recordBattleWin(Character $character): void
    {
        $quest = $this->weeklyQuestRepository->findCurrent();
        if (null === $quest || self::DEFAULT_TYPE !== $quest->getType()) {
            return;
        }

        $progress = $this->getOrCreateProgress($character, $quest);
        $progress->incrementProgress();
        $this->entityManager->flush();
    }

    public function getOrCreateProgress(Character $character, WeeklyQuest $quest): CharacterQuestProgress
    {
        $progress = $this->progressRepository->findOneByCharacterAndQuest($character, $quest);
        if (null === $progress) {
            $progress = new CharacterQuestProgress($character, $quest);
            $this->entityManager->persist($progress);
        }

        return $progress;
    }

    public function claim(Character $character, WeeklyQuest $quest): CharacterQuestProgress
    {
        $progress = $this->progressRepository->findOneByCharacterAndQuest($character, $quest);
        if (null === $progress || !$progress->isComplete()) {
            throw new QuestNotCompleteException('Quest is not complete yet.');
        }
        if ($progress->isClaimed()) {
            throw new QuestAlreadyClaimedException('Quest reward was already claimed.');
        }

        $character->addXp($quest->getRewardXp());
        $character->addCoins($quest->getRewardCoins());
        $progress->claim();
        $this->entityManager->flush();

        return $progress;
    }
}
