<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base for service-to-service endpoints used by the Discord bot process,
 * authenticated with a shared secret (BOT_API_SECRET) instead of end-user
 * JWTs — the bot already knows which Discord user it's acting for.
 *
 * Subclasses must accept $botApiSecret in their own constructor and forward
 * it via parent::__construct() — PHP doesn't merge parent/child constructor
 * parameter lists, so Symfony's autowiring needs it declared on both.
 */
abstract class AbstractBotController extends AbstractApiController
{
    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] private readonly string $botApiSecret,
    ) {
    }

    protected function checkSecret(Request $request): ?JsonResponse
    {
        if (!hash_equals($this->botApiSecret, (string) $request->headers->get('X-Bot-Secret'))) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }
}
