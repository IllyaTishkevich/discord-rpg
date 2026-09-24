<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Allows cross-origin /api requests from exactly the Activity Preview's
 * configured origin (ACTIVITY_PREVIEW_URL) — the one legitimate case that
 * needs it: the admin preview tool (ActivityPreviewController) embeds the
 * Activity from a separate origin (typically `npm run dev` on :5173 during
 * local development) via devToken, and that iframe's own JS then calls this
 * API directly, cross-origin — unlike a real embedded Discord Activity,
 * which always goes through Discord's own same-origin `/.proxy` reverse
 * proxy instead (see activity/src/api/client.ts's isEmbeddedInDiscord
 * branch) and so never needs this at all. Scoped this narrowly rather than
 * reflecting any Origin, since that's an admin-controlled value, not
 * something an untrusted request could influence.
 *
 * The kernel.request listener runs at a very high priority specifically so
 * it can short-circuit an OPTIONS preflight before routing or the /api
 * firewall's JWT check ever see it — a preflight never carries the
 * Authorization header (that's the whole point of it), so left to the
 * normal request cycle it would 401 before this class got a chance to
 * answer it.
 */
class CorsListener
{
    private readonly ?string $allowedOrigin;

    public function __construct(#[Autowire(env: 'ACTIVITY_PREVIEW_URL')] string $activityPreviewUrl)
    {
        $scheme = parse_url($activityPreviewUrl, \PHP_URL_SCHEME);
        $host = parse_url($activityPreviewUrl, \PHP_URL_HOST);
        $port = parse_url($activityPreviewUrl, \PHP_URL_PORT);

        $this->allowedOrigin = (null !== $scheme && null !== $host)
            ? \sprintf('%s://%s%s', $scheme, $host, null !== $port ? ":{$port}" : '')
            : null;
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('OPTIONS' !== $request->getMethod() || !$this->isCorsEligible($request)) {
            return;
        }

        $response = new Response('', 204);
        $this->addCorsHeaders($response, $request);
        $event->setResponse($response);
        $event->stopPropagation();
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isCorsEligible($request)) {
            return;
        }

        $this->addCorsHeaders($event->getResponse(), $request);
    }

    private function isCorsEligible(Request $request): bool
    {
        return null !== $this->allowedOrigin
            && str_starts_with($request->getPathInfo(), '/api')
            && $request->headers->get('Origin') === $this->allowedOrigin;
    }

    private function addCorsHeaders(Response $response, Request $request): void
    {
        $response->headers->set('Access-Control-Allow-Origin', (string) $request->headers->get('Origin'));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Vary', 'Origin');
    }
}
