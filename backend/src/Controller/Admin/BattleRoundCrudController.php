<?php

namespace App\Controller\Admin;

use App\Entity\BattleRound;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BattleRoundCrudController extends AbstractCrudController
{
    use ReadOnlyCrudTrait;

    public static function getEntityFqcn(): string
    {
        return BattleRound::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Раунд боя')->setEntityLabelInPlural('Раунды боя');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('battle', 'Бой');
        yield IntegerField::new('roundNumber', '№ раунда');
        yield TextField::new('playerFaces', 'Грани игрока')->formatValue(static fn ($v) => implode(', ', $v));
        yield TextField::new('opponentFaces', 'Грани противника')->formatValue(static fn ($v) => implode(', ', $v));
        yield IntegerField::new('damageToOpponent', 'Урон противнику');
        yield IntegerField::new('damageToPlayer', 'Урон игроку');
        yield DateTimeField::new('createdAt', 'Время');
    }
}
