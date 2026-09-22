<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EventCrudController extends AbstractCrudController
{
    // "type" and "startedAt" have no setters (fixed at construction) — the
    // proper way to start an event is EventService (bot's /event-start),
    // which also handles auto-ending the previous one.
    use NoCreateCrudTrait;

    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Событие')->setEntityLabelInPlural('События');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('type', 'Тип')->hideOnForm();
        yield TextField::new('monsterName', 'Имя монстра');
        yield IntegerField::new('monsterHp', 'HP монстра');
        yield ChoiceField::new('status', 'Статус');
        yield DateTimeField::new('startedAt', 'Начало')->hideOnForm();
        yield DateTimeField::new('endsAt', 'Окончание');
    }
}
