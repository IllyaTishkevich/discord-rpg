<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractApiController extends AbstractController
{
    protected function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
