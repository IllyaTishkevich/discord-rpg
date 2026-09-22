<?php

namespace App\Controller;

use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\BitFace;
use App\Repository\BitRepository;
use App\Repository\CharacterClassRepository;
use App\Repository\CharacterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/characters')]
class CharacterController extends AbstractApiController
{
    #[Route('', name: 'character_create', methods: ['POST'])]
    public function create(
        Request $request,
        CharacterClassRepository $characterClassRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->getCharacter()) {
            return $this->json(['error' => 'Character already exists for this user.'], 409);
        }

        $classCode = $this->decodeJson($request)['classCode'] ?? null;
        $characterClass = \is_string($classCode) ? $characterClassRepository->findOneByCode($classCode) : null;
        if (null === $characterClass) {
            return $this->json(['error' => 'Unknown or missing "classCode".'], 400);
        }

        $character = new Character($user, $characterClass);
        $user->setCharacter($character);
        $entityManager->persist($character);

        $bits = [];
        foreach ($characterClass->getStarterBits() as $starterBit) {
            $bit = new Bit(
                $character,
                BitFace::from($starterBit['faceA']),
                BitFace::from($starterBit['faceB']),
                $starterBit['advantageA'] ?? false,
                $starterBit['advantageB'] ?? false,
            );
            $entityManager->persist($bit);
            $bits[] = $bit;
        }

        $entityManager->flush();

        return $this->json($this->serializeCharacter($character, $bits), 201);
    }

    #[Route('/leaderboard', name: 'character_leaderboard', methods: ['GET'])]
    public function leaderboard(CharacterRepository $characterRepository): JsonResponse
    {
        $top = array_map(
            static fn (Character $character) => [
                'displayName' => $character->getUser()->getDisplayName(),
                'className' => $character->getCharacterClass()->getName(),
                'level' => $character->getLevel(),
                'xp' => $character->getXp(),
            ],
            $characterRepository->findTopByXp(10),
        );

        return $this->json($top);
    }

    #[Route('/me', name: 'character_me', methods: ['GET'])]
    public function me(BitRepository $bitRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $character = $user->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        return $this->json($this->serializeCharacter($character, $bitRepository->findByCharacter($character)));
    }

    /**
     * @param Bit[] $bits
     */
    private function serializeCharacter(Character $character, array $bits = []): array
    {
        return [
            'id' => $character->getId(),
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
            'bits' => array_map(
                static fn (Bit $bit) => ['faceA' => $bit->getFaceA()->value, 'faceB' => $bit->getFaceB()->value],
                $bits,
            ),
        ];
    }
}
