<?php

namespace App\Controller\Admin;

use App\Entity\Bit;
use App\Enum\BitFace;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Vich\UploaderBundle\Form\Type\VichImageType;

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
        // No explicit setChoices(): EasyAdmin auto-detects the enum type from
        // Doctrine's enumType mapping and renders a proper native EnumType
        // widget. Passing our own [value => case] map broke this — its keys
        // aren't a sequential list, so EasyAdmin's "are these all enum cases"
        // check failed and it fell back to auto-numbered option values.
        yield ChoiceField::new('faceA', 'Грань A');
        yield ChoiceField::new('faceB', 'Грань B');
        yield BooleanField::new('advantageA', 'Преимущество на A');
        yield BooleanField::new('advantageB', 'Преимущество на B');
        // Damage/blocking/action-points this face is worth — 1 is the
        // default ("normal" bit); see Bit::$multiplierA's docblock.
        yield IntegerField::new('multiplierA', 'Множитель A');
        yield IntegerField::new('multiplierB', 'Множитель B');
        yield ImageField::new('iconAName', 'Иконка A')
            ->setBasePath('/uploads/bits')
            ->onlyOnIndex();
        // VichImageType handles the actual upload (writes the file, fills
        // iconAName/iconASize on flush) — see Monster::$iconFile's docblock.
        yield Field::new('iconAFile', 'Иконка A (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
        yield ImageField::new('iconBName', 'Иконка B')
            ->setBasePath('/uploads/bits')
            ->onlyOnIndex();
        yield Field::new('iconBFile', 'Иконка B (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
    }

    /**
     * A Bit holds no reference to whoever uses it (see its docblock) —
     * assign it to a class from CharacterClassCrudController's "Стартовые
     * биты" field, or to a character's purchased bits elsewhere.
     */
    public function createEntity(string $entityFqcn): Bit
    {
        return new Bit(BitFace::Attack, BitFace::Attack);
    }
}
