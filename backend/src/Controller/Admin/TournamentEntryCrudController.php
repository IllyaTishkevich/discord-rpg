<?php

namespace App\Controller\Admin;

use App\Entity\TournamentEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class TournamentEntryCrudController extends AbstractCrudController
{
    use ReadOnlyCrudTrait;

    public static function getEntityFqcn(): string
    {
        return TournamentEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Заявка на турнир')->setEntityLabelInPlural('Заявки на турнир');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('tournament', 'Турнир');
        yield AssociationField::new('character', 'Персонаж');
        yield DateTimeField::new('joinedAt', 'Записался');
    }
}
