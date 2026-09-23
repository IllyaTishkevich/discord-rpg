<?php

namespace App\Controller\Admin;

use App\Entity\Equipment;
use App\Enum\EquipmentEffectType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class EquipmentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Equipment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Снаряжение')->setEntityLabelInPlural('Снаряжение');
    }

    /**
     * EasyAdmin's "New" action instantiates the entity with `new $fqcn()` —
     * Equipment's constructor requires args, so the placeholder values here
     * exist only to satisfy it; the form fields overwrite them via setters.
     */
    public function createEntity(string $entityFqcn): Equipment
    {
        return new Equipment('', '', 0, EquipmentEffectType::Hp);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Код');
        yield TextField::new('name', 'Название');
        yield TextareaField::new('description', 'Описание')->hideOnIndex();
        yield IntegerField::new('price', 'Цена');
        // No setChoices(): EasyAdmin auto-detects choices from the Doctrine
        // enumType mapping (see BitCrudController for why a manual map broke this).
        yield ChoiceField::new('effectType', 'Тип эффекта');
        yield ChoiceField::new('bitFaceA', 'Грань A (для bit)')->hideOnIndex();
        yield ChoiceField::new('bitFaceB', 'Грань B (для bit)')->hideOnIndex();
        yield BooleanField::new('bitAdvantageA', 'Преимущество на A (для bit)')->hideOnIndex();
        yield BooleanField::new('bitAdvantageB', 'Преимущество на B (для bit)')->hideOnIndex();
        yield IntegerField::new('hpBonus', 'Бонус HP (для hp)')->hideOnIndex();
        yield AssociationField::new('grantedAbility', 'Даёт способность')->hideOnIndex();
        yield ImageField::new('iconName', 'Иконка')
            ->setBasePath('/uploads/equipment')
            ->onlyOnIndex();
        yield Field::new('iconFile', 'Иконка (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
    }
}
