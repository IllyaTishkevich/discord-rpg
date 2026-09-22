<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);

        return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('discord-rpg admin')
            ->setLocales(['ru']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Дашборд', 'fa fa-home');

        yield MenuItem::section('Игроки');
        yield MenuItem::linkTo(UserCrudController::class, 'Пользователи', 'fa fa-user');
        yield MenuItem::linkTo(CharacterCrudController::class, 'Персонажи', 'fa fa-shield-alt');
        yield MenuItem::linkTo(CharacterClassCrudController::class, 'Классы персонажей', 'fa fa-list');
        yield MenuItem::linkTo(BitCrudController::class, 'Биты', 'fa fa-circle');

        yield MenuItem::section('Экономика');
        yield MenuItem::linkTo(EquipmentCrudController::class, 'Снаряжение', 'fa fa-shopping-bag');
        yield MenuItem::linkTo(CharacterEquipmentCrudController::class, 'Покупки', 'fa fa-receipt');

        yield MenuItem::section('Бои');
        yield MenuItem::linkTo(BattleCrudController::class, 'Бои', 'fa fa-fist-raised');
        yield MenuItem::linkTo(BattleRoundCrudController::class, 'Раунды боя', 'fa fa-list-ol');

        yield MenuItem::section('События и турниры');
        yield MenuItem::linkTo(EventCrudController::class, 'Ивенты', 'fa fa-fire');
        yield MenuItem::linkTo(TournamentCrudController::class, 'Турниры', 'fa fa-trophy');
        yield MenuItem::linkTo(TournamentEntryCrudController::class, 'Заявки на турнир', 'fa fa-user-plus');
        yield MenuItem::linkTo(TournamentMatchCrudController::class, 'Матчи турнира', 'fa fa-random');

        yield MenuItem::section('Задания');
        yield MenuItem::linkTo(WeeklyQuestCrudController::class, 'Недельные задания', 'fa fa-calendar-week');
        yield MenuItem::linkTo(CharacterQuestProgressCrudController::class, 'Прогресс заданий', 'fa fa-tasks');

        yield MenuItem::section('Администрирование');
        yield MenuItem::linkTo(AdminCrudController::class, 'Админы', 'fa fa-user-shield');
        yield MenuItem::linkToLogout('Выйти', 'fa fa-sign-out-alt');
    }
}
