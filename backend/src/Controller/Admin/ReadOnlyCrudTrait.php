<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;

/**
 * For entities whose lifecycle is fully owned by an application service
 * (BattleService, TournamentService, ...) — manually creating or editing one
 * through the admin form would bypass that logic and leave inconsistent
 * state, so only viewing and deleting are exposed.
 */
trait ReadOnlyCrudTrait
{
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }
}
