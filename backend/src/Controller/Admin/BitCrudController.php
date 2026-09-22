<?php

namespace App\Controller\Admin;

use App\Entity\Bit;
use App\Enum\BitFace;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class BitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Bit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Бита')->setEntityLabelInPlural('Биты');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        // Leave empty for a reusable class-starter template rather than a
        // specific character's owned bit — see Bit's docblock.
        yield AssociationField::new('character', 'Персонаж (пусто = шаблон для классов)')->setRequired(false);
        // No explicit setChoices(): EasyAdmin auto-detects the enum type from
        // Doctrine's enumType mapping and renders a proper native EnumType
        // widget. Passing our own [value => case] map broke this — its keys
        // aren't a sequential list, so EasyAdmin's "are these all enum cases"
        // check failed and it fell back to auto-numbered option values.
        yield ChoiceField::new('faceA', 'Грань A');
        yield ChoiceField::new('faceB', 'Грань B');
        yield BooleanField::new('advantageA', 'Преимущество на A');
        yield BooleanField::new('advantageB', 'Преимущество на B');
        yield AssociationField::new('characterClasses', 'Классы (стартовый набор)')->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): Bit
    {
        return new Bit(null, BitFace::Attack, BitFace::Attack);
    }
}
