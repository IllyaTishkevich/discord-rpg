<?php

namespace App\Controller\Admin;

use App\Entity\Item;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class ItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Item::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Предмет')->setEntityLabelInPlural('Предметы');
    }

    /**
     * EasyAdmin's "New" action instantiates via `new $fqcn()`; Item's
     * constructor requires args, so this placeholder exists only to satisfy
     * it — the form fields overwrite it via setters.
     */
    public function createEntity(string $entityFqcn): Item
    {
        return new Item('', 0, ItemType::Potion, ItemEffectType::Heal);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название');
        yield TextareaField::new('description', 'Описание')->hideOnIndex();
        yield IntegerField::new('price', 'Цена (продажа — половина)');
        // No setChoices(): EasyAdmin auto-detects choices from the Doctrine
        // enumType mapping (same convention as AbilityCrudController/EquipmentCrudController).
        yield ChoiceField::new('type', 'Тип предмета');
        yield ChoiceField::new('effectType', 'Тип эффекта');

        yield FormField::addFieldset('Постоянный эффект (оружие/щит/доспех/сумка)')->onlyOnForms();
        yield ChoiceField::new('bitFaceA', 'Грань A (для "Добавляет биту")')->hideOnIndex();
        yield ChoiceField::new('bitFaceB', 'Грань B (для "Добавляет биту")')->hideOnIndex();
        yield BooleanField::new('bitAdvantageA', 'Преимущество на A')->hideOnIndex();
        yield BooleanField::new('bitAdvantageB', 'Преимущество на B')->hideOnIndex();
        yield IntegerField::new('bitMultiplierA', 'Множитель на A')->hideOnIndex();
        yield IntegerField::new('bitMultiplierB', 'Множитель на B')->hideOnIndex();
        yield AssociationField::new('grantedAbility', 'Даёт способность (для "Добавляет способность")')->hideOnIndex();
        yield IntegerField::new('capacityBonus', 'Бонус ячеек (для "Увеличивает инвентарь")')->hideOnIndex();

        yield FormField::addFieldset('Мгновенный эффект (зелье/свиток)')->onlyOnForms();
        yield IntegerField::new('healAmount', 'HP (для "Восполняет HP")')->hideOnIndex();
        yield IntegerField::new('energyAmount', 'Энергия (для "Восполняет энергию")')->hideOnIndex();
        yield IntegerField::new('xpAmount', 'Опыт (для "Даёт опыт")')->hideOnIndex();

        yield ImageField::new('iconName', 'Иконка')
            ->setBasePath('/uploads/items')
            ->onlyOnIndex();
        yield Field::new('iconFile', 'Иконка (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
    }
}
