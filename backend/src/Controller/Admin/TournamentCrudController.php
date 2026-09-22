<?php

namespace App\Controller\Admin;

use App\Entity\Tournament;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class TournamentCrudController extends AbstractCrudController
{
    use ReadOnlyCrudTrait;

    public static function getEntityFqcn(): string
    {
        return Tournament::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Турнир')->setEntityLabelInPlural('Турниры');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield ChoiceField::new('status', 'Статус');
        yield AssociationField::new('championCharacter', 'Чемпион');
        yield DateTimeField::new('createdAt', 'Создан');
        yield DateTimeField::new('finishedAt', 'Завершён');
    }
}
