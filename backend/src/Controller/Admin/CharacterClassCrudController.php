<?php

namespace App\Controller\Admin;

use App\Entity\CharacterClass;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class CharacterClassCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CharacterClass::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Класс персонажа')->setEntityLabelInPlural('Классы персонажей');
    }

    /**
     * EasyAdmin's "New" action instantiates the entity with `new $fqcn()` —
     * the constructor requires args, so these placeholders exist only to
     * satisfy it; the form fields overwrite them via setters.
     */
    public function createEntity(string $entityFqcn): CharacterClass
    {
        return new CharacterClass('', '', 0, 0);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Код');
        yield TextField::new('name', 'Название');
        yield TextareaField::new('description', 'Описание')->hideOnIndex();
        yield IntegerField::new('baseHp', 'Базовое HP');
        yield IntegerField::new('baseEnergy', 'Базовая энергия');
        yield ImageField::new('iconName', 'Иконка')
            ->setBasePath('/uploads/classes')
            ->onlyOnIndex();
        yield Field::new('iconFile', 'Иконка (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
        yield ImageField::new('frameName', 'Рамка аватара')
            ->setBasePath('/uploads/frames')
            ->onlyOnIndex();
        yield Field::new('frameFile', 'Рамка аватара (PNG)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
        yield TextField::new('starterBitsSummary', 'Стартовые биты')->hideOnForm();
        // Editable many-to-many: pick which template Bit rows (character
        // === null) this class starts with — see Bit's docblock.
        yield AssociationField::new('starterBits', 'Стартовые биты')->hideOnIndex();
        yield AssociationField::new('abilities', 'Способности класса')->hideOnIndex();
    }
}
