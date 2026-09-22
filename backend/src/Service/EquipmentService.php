<?php

namespace App\Service;

use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterEquipment;
use App\Entity\Equipment;
use App\Enum\EquipmentEffectType;
use App\Exception\InsufficientCoinsException;
use Doctrine\ORM\EntityManagerInterface;

class EquipmentService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function purchase(Character $character, Equipment $equipment): CharacterEquipment
    {
        if (!$character->trySpendCoins($equipment->getPrice())) {
            throw new InsufficientCoinsException('Not enough coins to buy this item.');
        }

        if (EquipmentEffectType::Bit === $equipment->getEffectType()) {
            $this->entityManager->persist(new Bit($character, $equipment->getBitFaceA(), $equipment->getBitFaceB()));
        } else {
            $character->increaseMaxHp($equipment->getHpBonus());
        }

        $purchase = new CharacterEquipment($character, $equipment);
        $this->entityManager->persist($purchase);
        $this->entityManager->flush();

        return $purchase;
    }
}
