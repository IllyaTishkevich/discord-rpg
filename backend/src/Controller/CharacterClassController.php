<?php

namespace App\Controller;

use App\Entity\Bit;
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
                'starterBits' => array_map(
                    static fn (Bit $bit) => [
                        'faceA' => $bit->getFaceA()->value,
                        'faceB' => $bit->getFaceB()->value,
                        'advantageA' => $bit->hasAdvantageA(),
                        'advantageB' => $bit->hasAdvantageB(),
                        'multiplierA' => $bit->getMultiplierA(),
                        'multiplierB' => $bit->getMultiplierB(),
                    ],
                    $class->getStarterBits()->toArray(),
                ),
            ],
            $characterClassRepository->findAll(),
        );

        return $this->json($classes);
    }
}
