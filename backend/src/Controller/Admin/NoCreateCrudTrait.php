<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;

/**
 * For entities whose constructor requires values that either have no admin
 * form field or no setter to bind one (association identity, timestamps set
 * at construction time, ...). EasyAdmin's "New" action instantiates the
 * entity WITHOUT calling its constructor, so anything not covered by a
 * settable form field is left an uninitialized typed property — a crash on
 * save. Editing an existing (already-hydrated) row is unaffected, so only
 * "New" is disabled here.
 */
trait NoCreateCrudTrait
{
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }
}
