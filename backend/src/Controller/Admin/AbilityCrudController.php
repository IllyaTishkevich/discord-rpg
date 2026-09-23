<?php

namespace App\Controller\Admin;

use App\Entity\Ability;
use App\Enum\AbilityType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class AbilityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Ability::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Способность')->setEntityLabelInPlural('Способности');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        // No explicit setChoices(): EasyAdmin auto-detects the enum type from
        // Doctrine's enumType mapping (see BitCrudController for why a manual
        // map broke this).
        yield ChoiceField::new('type', 'Тип');
    }

    /**
     * The 4 AbilityType cases are fixed and seeded by
     * Version20260923113632 — this only exists to satisfy EasyAdmin's "New"
     * action, which instantiates the entity with `new $fqcn()` before the
     * required-arg constructor has a value from the form.
     */
    public function createEntity(string $entityFqcn): Ability
    {
        return new Ability(AbilityType::Flip);
    }
}
