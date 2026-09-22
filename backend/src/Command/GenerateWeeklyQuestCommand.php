<?php

namespace App\Command;

use App\Service\QuestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent — safe to run from a system cron every Monday (or more often;
 * it's a no-op once this week's quest already exists). Example crontab:
 *   0 6 * * 1  php /var/www/app/bin/console app:weekly-quests:generate
 */
#[AsCommand(name: 'app:weekly-quests:generate', description: 'Generate this week\'s quest if it does not exist yet')]
class GenerateWeeklyQuestCommand extends Command
{
    public function __construct(private readonly QuestService $questService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $quest = $this->questService->generateForCurrentWeek();

        $io->success(sprintf(
            'Weekly quest for week of %s: win %d battles for +%d XP / +%d coins.',
            $quest->getWeekStart()->format('Y-m-d'),
            $quest->getTargetValue(),
            $quest->getRewardXp(),
            $quest->getRewardCoins(),
        ));

        return Command::SUCCESS;
    }
}
