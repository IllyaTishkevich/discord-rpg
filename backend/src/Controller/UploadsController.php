<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thin passthrough for uploaded catalog icons (Monster/Bit/Ability/
 * Equipment/Item — Vich writes them to public/uploads/<dir>/, see
 * config/packages/vich_uploader.yaml), reachable under /api/... so the
 * Activity can load them through the exact same BASE_URL (and therefore
 * the exact same Discord proxy mapping) it already uses for every other
 * request — activity/src/api/client.ts. A bare /uploads/... prefix
 * requires its *own* separate Discord Developer Portal URL Mapping entry
 * to be proxied inside the real Discord client (docs/PRODUCTION_SETUP.md
 * §A.2/§B.8); depending on that existing, on top of nginx's own /uploads/
 * location (§B.8, used directly by the admin panel, which isn't behind
 * Discord's proxy at all), was fragile — this route removes that second
 * dependency entirely for the Activity specifically.
 */
class UploadsController extends AbstractController
{
    #[Route(
        '/api/uploads/{directory}/{filename}',
        name: 'uploads_show',
        requirements: [
            'directory' => 'monsters|bits|classes|abilities|equipment|items',
            'filename' => '[A-Za-z0-9._-]+',
        ],
        methods: ['GET'],
    )]
    public function show(string $directory, string $filename, KernelInterface $kernel): Response
    {
        $path = $kernel->getProjectDir().'/public/uploads/'.$directory.'/'.$filename;
        if (!is_file($path)) {
            throw $this->createNotFoundException('Unknown upload.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
