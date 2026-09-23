<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterInventoryItem;
use App\Entity\User;
use App\Exception\ItemNotOwnedException;
use App\Repository\CharacterInventoryItemRepository;
use App\Service\InventoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/inventory')]
class InventoryController extends AbstractApiController
{
    #[Route('', name: 'inventory_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $character = $this->requireCharacter();

        return $this->json([
            'capacity' => $character->getInventoryCapacity(),
            'items' => array_map(
                fn (CharacterInventoryItem $row) => $this->serializeRow($row),
                $character->getInventoryItems()->toArray(),
            ),
        ]);
    }

    #[Route('/{id}/use', name: 'inventory_use', methods: ['POST'])]
    public function use(int $id, CharacterInventoryItemRepository $repository, InventoryService $inventoryService): JsonResponse
    {
        return $this->act($id, $repository, function (Character $character, CharacterInventoryItem $row) use ($inventoryService) {
            $inventoryService->useItem($character, $row);
        });
    }

    #[Route('/{id}/sell', name: 'inventory_sell', methods: ['POST'])]
    public function sell(int $id, CharacterInventoryItemRepository $repository, InventoryService $inventoryService): JsonResponse
    {
        return $this->act($id, $repository, function (Character $character, CharacterInventoryItem $row) use ($inventoryService) {
            $inventoryService->sellItem($character, $row);
        });
    }

    #[Route('/{id}/discard', name: 'inventory_discard', methods: ['POST'])]
    public function discard(int $id, CharacterInventoryItemRepository $repository, InventoryService $inventoryService): JsonResponse
    {
        return $this->act($id, $repository, function (Character $character, CharacterInventoryItem $row) use ($inventoryService) {
            $inventoryService->discardItem($character, $row);
        });
    }

    private function act(int $id, CharacterInventoryItemRepository $repository, callable $action): JsonResponse
    {
        $character = $this->requireCharacter();

        $row = $repository->find($id);
        if (null === $row) {
            return $this->json(['error' => 'Unknown inventory item.'], 404);
        }

        try {
            $action($character, $row);
        } catch (ItemNotOwnedException) {
            return $this->json(['error' => 'Unknown inventory item.'], 404);
        }

        return $this->json([
            'coins' => $character->getCoins(),
            'hp' => $character->getHp(),
            'energy' => $character->getEnergy(),
            'xp' => $character->getXp(),
            'capacity' => $character->getInventoryCapacity(),
            'items' => array_map(
                fn (CharacterInventoryItem $row) => $this->serializeRow($row),
                $character->getInventoryItems()->toArray(),
            ),
        ]);
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

    private function serializeRow(CharacterInventoryItem $row): array
    {
        $item = $row->getItem();

        return [
            'id' => $row->getId(),
            'name' => $item->getName(),
            'description' => $item->getDescription(),
            'type' => $item->getType()->value,
            'effectType' => $item->getEffectType()->value,
            'iconName' => $item->getIconName(),
            'equipped' => $row->isEquipped(),
        ];
    }
}
