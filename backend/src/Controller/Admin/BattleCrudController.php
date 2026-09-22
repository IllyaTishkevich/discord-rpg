<?php

namespace App\Controller\Admin;

use App\Entity\Battle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BattleCrudController extends AbstractCrudController
{
    use ReadOnlyCrudTrait;

    public static function getEntityFqcn(): string
    {
        return Battle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Бой')->setEntityLabelInPlural('Бои');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield ChoiceField::new('mode', 'Режим');
        yield AssociationField::new('character', 'Персонаж');
        yield AssociationField::new('opponentCharacter', 'Соперник (PvP)')->hideOnIndex();
        yield AssociationField::new('event', 'Событие')->hideOnIndex();
        yield TextField::new('opponentName', 'Противник (PvE/ивент)')->hideOnIndex();
        yield IntegerField::new('opponentHp', 'HP противника (PvE/ивент)')->hideOnIndex();
        yield IntegerField::new('opponentMaxHp', 'Макс. HP противника (PvE/ивент)')->hideOnIndex();
        yield ChoiceField::new('status', 'Статус');
        yield IntegerField::new('roundNumber', 'Раунд');
        yield DateTimeField::new('createdAt', 'Начат');
        yield DateTimeField::new('finishedAt', 'Завершён')->hideOnIndex();
    }
}
