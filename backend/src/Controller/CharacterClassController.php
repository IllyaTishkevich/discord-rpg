<?php

namespace App\Controller;

use App\Repository\CharacterClassRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/character-classes')]
class CharacterClassController extends AbstractApiController
{
    #[Route('', name: 'character_class_list', methods: ['GET'])]
    public function list(CharacterClassRepository $characterClassRepository): JsonResponse
    {
        $classes = array_map(
            static fn ($class) => [
                'code' => $class->getCode(),
                'name' => $class->getName(),
                'description' => $class->getDescription(),
                'baseHp' => $class->getBaseHp(),
                'baseEnergy' => $class->getBaseEnergy(),
                'starterBits' => $class->getStarterBits(),
            ],
            $characterClassRepository->findAll(),
        );

        return $this->json($classes);
    }
}
