<?php

namespace App\Controller;

use App\Entity\Ability;
use App\Repository\AbilityRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ability catalog — labels/descriptions for the Activity's ability
 * picker (ArenaScreen.tsx), which otherwise only ever sees bare
 * AbilityType values via Character::$abilities (see CharacterController).
 */
#[Route('/api/abilities')]
class AbilityController extends AbstractApiController
{
    #[Route('', name: 'abilities_list', methods: ['GET'])]
    public function list(AbilityRepository $abilityRepository): JsonResponse
    {
        return $this->json(array_map(
            fn (Ability $ability) => [
                'type' => $ability->getType()->value,
                'label' => $ability->getLabel(),
                'description' => $ability->getDescription(),
            ],
            $abilityRepository->findAll(),
        ));
    }
}
