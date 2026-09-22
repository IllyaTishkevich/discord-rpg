<?php

namespace App\Command;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants admin-panel access to a Google account. There's no self-registration
 * by design — the panel authenticates via Google OAuth, but only emails
 * added here are let in (see Security\GoogleAuthenticator).
 */
#[AsCommand(name: 'app:admin:add', description: 'Grant EasyAdmin panel access to a Google account by email')]
class AddAdminCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The Google account email')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name')
        ;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminRepository $adminRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $name = $input->getArgument('name');

        if (null !== $this->adminRepository->findOneByEmail($email)) {
            $io->warning(sprintf('"%s" is already an admin.', $email));

            return Command::SUCCESS;
        }

        $admin = new Admin($email, $name);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $io->success(sprintf('"%s" (%s) can now sign in to /admin with Google.', $name, $email));

        return Command::SUCCESS;
    }
}
