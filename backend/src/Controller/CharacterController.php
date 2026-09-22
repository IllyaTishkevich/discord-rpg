<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterClassRepository;
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
        $entityManager->flush();

        return $this->json($this->serializeCharacter($character), 201);
    }

    #[Route('/me', name: 'character_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $character = $user->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        return $this->json($this->serializeCharacter($character));
    }

    private function serializeCharacter(Character $character): array
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
        ];
    }
}
