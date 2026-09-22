<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterEquipment;
use App\Entity\Equipment;
use App\Entity\User;
use App\Exception\InsufficientCoinsException;
use App\Repository\CharacterEquipmentRepository;
use App\Repository\EquipmentRepository;
use App\Service\EquipmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/equipment')]
class EquipmentController extends AbstractApiController
{
    #[Route('', name: 'equipment_list', methods: ['GET'])]
    public function list(EquipmentRepository $equipmentRepository): JsonResponse
    {
        return $this->json(array_map(
            fn (Equipment $equipment) => $this->serializeEquipment($equipment),
            $equipmentRepository->findAll(),
        ));
    }

    #[Route('/inventory', name: 'equipment_inventory', methods: ['GET'])]
    public function inventory(CharacterEquipmentRepository $characterEquipmentRepository): JsonResponse
    {
        $character = $this->requireCharacter();

        $items = array_map(
            fn (CharacterEquipment $purchase) => [
                ...$this->serializeEquipment($purchase->getEquipment()),
                'purchasedAt' => $purchase->getPurchasedAt()->format(\DateTimeInterface::ATOM),
            ],
            $characterEquipmentRepository->findByCharacter($character),
        );

        return $this->json($items);
    }

    #[Route('/{code}/purchase', name: 'equipment_purchase', methods: ['POST'])]
    public function purchase(string $code, EquipmentRepository $equipmentRepository, EquipmentService $equipmentService): JsonResponse
    {
        $character = $this->requireCharacter();

        $equipment = $equipmentRepository->findOneByCode($code);
        if (null === $equipment) {
            return $this->json(['error' => 'Unknown equipment code.'], 404);
        }

        try {
            $equipmentService->purchase($character, $equipment);
        } catch (InsufficientCoinsException) {
            return $this->json(['error' => 'Not enough coins.'], 409);
        }

        return $this->json([
            'coins' => $character->getCoins(),
            'maxHp' => $character->getMaxHp(),
            'hp' => $character->getHp(),
        ], 201);
    }

    private function requireCharacter(): Character
    {
        /** @var User $user */
        $user = $this->getUser();
        $character = $user->getCharacter();
        if (null === $character) {
            throw $this->createNotFoundException('No character for this user yet.');
        }

        return $character;
    }

    private function serializeEquipment(Equipment $equipment): array
    {
        return [
            'code' => $equipment->getCode(),
            'name' => $equipment->getName(),
            'description' => $equipment->getDescription(),
            'price' => $equipment->getPrice(),
            'effectType' => $equipment->getEffectType()->value,
            'bitFaceA' => $equipment->getBitFaceA()?->value,
            'bitFaceB' => $equipment->getBitFaceB()?->value,
            'hpBonus' => $equipment->getHpBonus(),
        ];
    }
}
