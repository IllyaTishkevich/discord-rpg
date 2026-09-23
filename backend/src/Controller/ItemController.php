<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\Item;
use App\Entity\User;
use App\Exception\InsufficientCoinsException;
use App\Exception\InventoryFullException;
use App\Repository\ItemRepository;
use App\Service\InventoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shop's catalog — every Item that exists, buyable by anyone (unlike
 * InventoryController, which only ever acts on items the calling character
 * already owns).
 */
#[Route('/api/items')]
class ItemController extends AbstractApiController
{
    #[Route('', name: 'items_list', methods: ['GET'])]
    public function list(ItemRepository $itemRepository): JsonResponse
    {
        return $this->json(array_map(
            fn (Item $item) => $this->serializeItem($item),
            $itemRepository->findAll(),
        ));
    }

    #[Route('/{id}/purchase', name: 'items_purchase', methods: ['POST'])]
    public function purchase(int $id, ItemRepository $itemRepository, InventoryService $inventoryService): JsonResponse
    {
        $character = $this->requireCharacter();

        $item = $itemRepository->find($id);
        if (null === $item) {
            return $this->json(['error' => 'Unknown item.'], 404);
        }

        try {
            $inventoryService->purchaseItem($character, $item);
        } catch (InsufficientCoinsException) {
            return $this->json(['error' => 'Not enough coins.'], 409);
        } catch (InventoryFullException) {
            return $this->json(['error' => 'Inventory is full.'], 409);
        }

        return $this->json(['coins' => $character->getCoins()], 201);
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

    private function serializeItem(Item $item): array
    {
        return [
            'id' => $item->getId(),
            'name' => $item->getName(),
            'description' => $item->getDescription(),
            'price' => $item->getPrice(),
            'type' => $item->getType()->value,
            'effectType' => $item->getEffectType()->value,
            'iconName' => $item->getIconName(),
        ];
    }
}
