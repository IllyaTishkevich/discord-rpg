<?php

namespace App\Controller\Admin;

use App\Entity\WeeklyQuest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class WeeklyQuestCrudController extends AbstractCrudController
{
    // weekStart/type have no setters — a new week's quest is meant to come
    // from app:weekly-quests:generate (idempotent, cron-triggered).
    use NoCreateCrudTrait;

    public static function getEntityFqcn(): string
    {
        return WeeklyQuest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Недельное задание')->setEntityLabelInPlural('Недельные задания');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield DateField::new('weekStart', 'Неделя')->hideOnForm();
        yield TextField::new('type', 'Тип')->hideOnForm();
        yield IntegerField::new('targetValue', 'Цель');
        yield IntegerField::new('rewardXp', 'Награда XP');
        yield IntegerField::new('rewardCoins', 'Награда монет');
    }
}
