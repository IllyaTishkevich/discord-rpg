<?php

namespace App\Controller\Admin;

use App\Entity\CharacterQuestProgress;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class CharacterQuestProgressCrudController extends AbstractCrudController
{
    // character/quest have no setters — a progress row is created the first
    // time a character wins a battle that week (see QuestService). Editable
    // here for support corrections; just not creatable from scratch.
    use NoCreateCrudTrait;

    public static function getEntityFqcn(): string
    {
        return CharacterQuestProgress::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Прогресс задания')->setEntityLabelInPlural('Прогресс заданий');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('character', 'Персонаж')->hideOnForm();
        yield AssociationField::new('quest', 'Задание')->hideOnForm();
        yield IntegerField::new('progress', 'Прогресс');
        yield DateTimeField::new('claimedAt', 'Награда получена');
    }
}
