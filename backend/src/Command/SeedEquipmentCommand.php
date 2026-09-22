<?php

namespace App\Command;

use App\Entity\Equipment;
use App\Enum\BitFace;
use App\Enum\EquipmentEffectType;
use App\Repository\EquipmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds a placeholder equipment catalog so the shop API is testable
 * end-to-end. Final pricing/balance is tracked in docs/ROADMAP.md under
 * "Game Design (Backlog)".
 */
#[AsCommand(name: 'app:seed-equipment', description: 'Seed placeholder shop equipment')]
class SeedEquipmentCommand extends Command
{
    private const ITEMS = [
        ['code' => 'leather_vambraces', 'name' => 'Кожаные наручи', 'price' => 20, 'type' => 'hp', 'hpBonus' => 5],
        ['code' => 'novice_shield', 'name' => 'Щит новичка', 'price' => 50, 'type' => 'hp', 'hpBonus' => 10],
        ['code' => 'lucky_coin', 'name' => 'Монета удачи', 'price' => 30, 'type' => 'bit', 'faceA' => BitFace::Attack, 'faceB' => BitFace::Action, 'advantageA' => true, 'advantageB' => true],
        ['code' => 'twin_blade', 'name' => 'Двойной клинок', 'price' => 40, 'type' => 'bit', 'faceA' => BitFace::Attack, 'faceB' => BitFace::Attack, 'advantageA' => false, 'advantageB' => false],
    ];

    public function __construct(
        private readonly EquipmentRepository $equipmentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::ITEMS as $definition) {
            if (null !== $this->equipmentRepository->findOneByCode($definition['code'])) {
                $io->writeln(sprintf('Skipping existing item "%s".', $definition['code']));
                continue;
            }

            $effectType = 'hp' === $definition['type'] ? EquipmentEffectType::Hp : EquipmentEffectType::Bit;
            $equipment = new Equipment($definition['code'], $definition['name'], $definition['price'], $effectType);

            if (EquipmentEffectType::Hp === $effectType) {
                $equipment->setHpBonus($definition['hpBonus']);
            } else {
                $equipment->setBitFaces($definition['faceA'], $definition['faceB'], $definition['advantageA'] ?? false, $definition['advantageB'] ?? false);
            }

            $this->entityManager->persist($equipment);
            $io->writeln(sprintf('Created item "%s".', $definition['code']));
        }

        $this->entityManager->flush();
        $io->success('Equipment catalog seeded.');

        return Command::SUCCESS;
    }
}
