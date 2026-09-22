<?php

namespace App\Controller\Admin;

use App\Entity\Bit;
use App\Enum\BitFace;
use App\Repository\CharacterRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class BitCrudController extends AbstractCrudController
{
    public function __construct(private readonly CharacterRepository $characterRepository)
    {
    }

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
        yield AssociationField::new('character', 'Персонаж');
        // No explicit setChoices(): EasyAdmin auto-detects the enum type from
        // Doctrine's enumType mapping and renders a proper native EnumType
        // widget. Passing our own [value => case] map broke this — its keys
        // aren't a sequential list, so EasyAdmin's "are these all enum cases"
        // check failed and it fell back to auto-numbered option values.
        yield ChoiceField::new('faceA', 'Грань A');
        yield ChoiceField::new('faceB', 'Грань B');
        yield BooleanField::new('advantageA', 'Преимущество на A');
        yield BooleanField::new('advantageB', 'Преимущество на B');
    }

    /**
     * EasyAdmin's "New" action instantiates the entity with `new $fqcn()` —
     * Bit's constructor requires a real Character, unlike the other
     * New-enabled controllers' scalar-only constructors, so we grab any
     * existing one as a placeholder; the "character" form field overwrites
     * it via setCharacter() on submit.
     */
    public function createEntity(string $entityFqcn): Bit
    {
        $character = $this->characterRepository->findOneBy([]);
        if (null === $character) {
            throw new \RuntimeException('Cannot create a Bit: no characters exist yet.');
        }

        return new Bit($character, BitFace::Attack, BitFace::Attack);
    }
}
