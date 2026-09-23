<?php

namespace App\Controller\Admin;

use App\Entity\Monster;
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

class MonsterCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Monster::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Монстр')->setEntityLabelInPlural('Монстры');
    }

    /**
     * EasyAdmin's "New" action instantiates the entity with `new $fqcn()` —
     * the constructor requires args, so these placeholders exist only to
     * satisfy it; the form fields overwrite them via setters.
     */
    public function createEntity(string $entityFqcn): Monster
    {
        return new Monster('', 1, 1);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Название');
        yield TextareaField::new('description', 'Описание')->hideOnIndex();
        yield IntegerField::new('level', 'Уровень');
        yield IntegerField::new('maxHp', 'Макс. HP');
        yield ImageField::new('iconName', 'Иконка')
            ->setBasePath('/uploads/monsters')
            ->onlyOnIndex();
        // VichImageType handles the actual upload (writes the file, fills
        // iconName/iconSize on flush) — see Monster::$iconFile's docblock.
        yield Field::new('iconFile', 'Иконка (256x256)')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
        yield TextField::new('bitsSummary', 'Биты')->hideOnForm();
        // Editable many-to-many, same unidirectional pattern as
        // CharacterClassCrudController's "Стартовые биты".
        yield AssociationField::new('bits', 'Биты')->hideOnIndex();
        yield AssociationField::new('abilities', 'Способности')->hideOnIndex();
    }
}
