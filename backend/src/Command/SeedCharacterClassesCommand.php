<?php

namespace App\Command;

use App\Entity\Bit;
use App\Entity\CharacterClass;
use App\Enum\BitFace;
use App\Repository\AbilityRepository;
use App\Repository\CharacterClassRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds a placeholder set of character classes so the character-creation API
 * is testable end-to-end. Final class design is tracked in docs/ROADMAP.md
 * under "Game Design (Backlog)" and should replace these values.
 */
#[AsCommand(name: 'app:seed-character-classes', description: 'Seed placeholder character classes')]
class SeedCharacterClassesCommand extends Command
{
    // "advantageA"/"advantageB" mark which faces grant a point of "преимущество"
    // (see docs/COMBAT_V2_DESIGN.md §1-2) — placeholder distribution, rogue
    // leans into advantage/initiative as its class identity, warrior/mage less so.
    private const CLASSES = [
        [
            'code' => 'warrior',
            'name' => 'Воин',
            'baseHp' => 30,
            'baseEnergy' => 10,
            'starterBits' => [
                ['faceA' => 'attack', 'faceB' => 'attack', 'advantageA' => true, 'advantageB' => false],
                ['faceA' => 'attack', 'faceB' => 'defense', 'advantageA' => true, 'advantageB' => false],
                ['faceA' => 'defense', 'faceB' => 'action', 'advantageA' => false, 'advantageB' => true],
            ],
        ],
        [
            'code' => 'mage',
            'name' => 'Маг',
            'baseHp' => 20,
            'baseEnergy' => 15,
            'starterBits' => [
                ['faceA' => 'attack', 'faceB' => 'action', 'advantageA' => false, 'advantageB' => true],
                ['faceA' => 'action', 'faceB' => 'action', 'advantageA' => true, 'advantageB' => false],
                ['faceA' => 'defense', 'faceB' => 'attack', 'advantageA' => false, 'advantageB' => false],
            ],
        ],
        [
            'code' => 'rogue',
            'name' => 'Плут',
            'baseHp' => 24,
            'baseEnergy' => 12,
            'starterBits' => [
                ['faceA' => 'attack', 'faceB' => 'defense', 'advantageA' => true, 'advantageB' => true],
                ['faceA' => 'attack', 'faceB' => 'action', 'advantageA' => true, 'advantageB' => false],
                ['faceA' => 'defense', 'faceB' => 'defense', 'advantageA' => true, 'advantageB' => false],
            ],
        ],
    ];

    public function __construct(
        private readonly CharacterClassRepository $characterClassRepository,
        private readonly AbilityRepository $abilityRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Seeded by the migration that introduced Ability (see
        // Version20260923113632) — every class starts with every ability
        // available, same as before this became configurable.
        $allAbilities = $this->abilityRepository->findAll();

        foreach (self::CLASSES as $definition) {
            if (null !== $this->characterClassRepository->findOneByCode($definition['code'])) {
                $io->writeln(sprintf('Skipping existing class "%s".', $definition['code']));
                continue;
            }

            $characterClass = new CharacterClass(
                $definition['code'],
                $definition['name'],
                $definition['baseHp'],
                $definition['baseEnergy'],
            );
            $this->entityManager->persist($characterClass);

            foreach ($definition['starterBits'] as $bitDefinition) {
                $templateBit = new Bit(
                    BitFace::from($bitDefinition['faceA']),
                    BitFace::from($bitDefinition['faceB']),
                    $bitDefinition['advantageA'] ?? false,
                    $bitDefinition['advantageB'] ?? false,
                );
                $this->entityManager->persist($templateBit);
                $characterClass->addStarterBit($templateBit);
            }

            foreach ($allAbilities as $ability) {
                $characterClass->addAbility($ability);
            }

            $io->writeln(sprintf('Created class "%s".', $definition['code']));
        }

        $this->entityManager->flush();
        $io->success('Character classes seeded.');

        return Command::SUCCESS;
    }
}
