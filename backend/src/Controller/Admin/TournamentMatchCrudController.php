<?php

namespace App\Controller\Admin;

use App\Entity\TournamentMatch;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class TournamentMatchCrudController extends AbstractCrudController
{
    use ReadOnlyCrudTrait;

    public static function getEntityFqcn(): string
    {
        return TournamentMatch::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Матч турнира')->setEntityLabelInPlural('Матчи турнира');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('tournament', 'Турнир');
        yield IntegerField::new('roundNumber', 'Раунд');
        yield IntegerField::new('slot', 'Слот');
        yield AssociationField::new('characterA', 'Участник A');
        yield AssociationField::new('characterB', 'Участник B');
        yield AssociationField::new('winner', 'Победитель');
        yield BooleanField::new('isBye', 'Проход без боя');
    }
}
