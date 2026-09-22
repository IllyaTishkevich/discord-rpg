<?php

namespace App\Controller\Admin;

use App\Entity\Admin;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AdminCrudController extends AbstractCrudController
{
    // Deliberately no self-registration: grant access with
    // `php bin/console app:admin:add <email> <name>`. The panel can rename
    // or revoke (delete) an admin, but not create one — email has no setter
    // and there's no reason to let a compromised admin session mint others.
    use NoCreateCrudTrait;

    public static function getEntityFqcn(): string
    {
        return Admin::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Админ')->setEntityLabelInPlural('Админы');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('email', 'Email')->hideOnForm();
        yield TextField::new('name', 'Имя');
        yield DateTimeField::new('createdAt', 'Добавлен')->hideOnForm();
    }
}
