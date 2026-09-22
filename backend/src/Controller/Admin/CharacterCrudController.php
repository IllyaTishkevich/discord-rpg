<?php

namespace App\Controller\Admin;

use App\Entity\Character;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class CharacterCrudController extends AbstractCrudController
{
    // Character has no setUser()/setCharacterClass() — identity fields set
    // once at creation (via CharacterController). Creating one from the
    // admin panel would skip that entirely, so only editing stats on an
    // existing character is exposed.
    use NoCreateCrudTrait;

    public static function getEntityFqcn(): string
    {
        return Character::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Персонаж')->setEntityLabelInPlural('Персонажи');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('user', 'Игрок')->hideOnForm();
        yield AssociationField::new('characterClass', 'Класс')->hideOnForm();
        yield IntegerField::new('maxHp', 'Макс. HP');
        yield IntegerField::new('hp', 'HP');
        yield IntegerField::new('maxEnergy', 'Макс. энергия');
        yield IntegerField::new('energy', 'Энергия');
        yield IntegerField::new('level', 'Уровень');
        yield IntegerField::new('xp', 'XP');
        yield IntegerField::new('coins', 'Монеты');
        yield AssociationField::new('purchasedBits', 'Купленные биты')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }
}
