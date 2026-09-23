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
        yield IntegerField::new('maxHp', 'Макс. HP (база)');
        // Read-only — includes a currently-equipped IncreaseMaxHp item's
        // bonus (Character::getEffectiveMaxHp()), which the base field
        // above deliberately does not, to avoid an admin silently baking a
        // temporary equipped bonus into the persisted base on save (see
        // that method's docblock).
        yield IntegerField::new('effectiveMaxHp', 'Макс. HP (эфф.)')->onlyOnIndex();
        yield IntegerField::new('hp', 'HP');
        yield IntegerField::new('maxEnergy', 'Макс. энергия (база)');
        yield IntegerField::new('effectiveMaxEnergy', 'Макс. энергия (эфф.)')->onlyOnIndex();
        yield IntegerField::new('energy', 'Энергия');
        yield IntegerField::new('level', 'Уровень');
        yield IntegerField::new('xp', 'XP');
        yield IntegerField::new('coins', 'Монеты');
        yield AssociationField::new('purchasedBits', 'Купленные биты')->hideOnIndex();
        yield AssociationField::new('abilities', 'Личные способности')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }
}
