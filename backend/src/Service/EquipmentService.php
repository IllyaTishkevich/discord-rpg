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
            $bit = new Bit(
                $equipment->getBitFaceA(),
                $equipment->getBitFaceB(),
                $equipment->hasBitAdvantageA(),
                $equipment->hasBitAdvantageB(),
                $equipment->getBitMultiplierA(),
                $equipment->getBitMultiplierB(),
            );
            $this->entityManager->persist($bit);
            $character->addPurchasedBit($bit);
        } else {
            $character->increaseMaxHp($equipment->getHpBonus());
        }

        // Independent of the effect above — an item can grant an ability
        // regardless of whether it's an HP or bit item (see Equipment's
        // docblock on $grantedAbility).
        if (null !== $equipment->getGrantedAbility()) {
            $character->addAbility($equipment->getGrantedAbility());
        }

        $purchase = new CharacterEquipment($character, $equipment);
        $this->entityManager->persist($purchase);
        $this->entityManager->flush();

        return $purchase;
    }
}
