<?php

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for a real bug: the admin Activity preview tool
 * (ActivityPreviewController) embeds the Activity from ACTIVITY_PREVIEW_URL
 * — a separate origin from the backend during local development (e.g.
 * `npm run dev` on :5173 vs the backend on :8000) — and that iframe calls
 * this API directly, cross-origin, since it isn't going through Discord's
 * own same-origin `/.proxy` reverse proxy the way a real embedded Activity
 * does. With no CORS support at all, every such call failed in the browser
 * before even reaching the backend ("No 'Access-Control-Allow-Origin'
 * header is present"), leaving the preview a blank screen.
 */
class CorsListenerTest extends WebTestCase
{
    private const ALLOWED_ORIGIN = 'http://localhost:5173'; // ACTIVITY_PREVIEW_URL in .env, unset in .env.test

    public function testPreflightFromTheActivityPreviewOriginIsAllowedWithoutAuth(): void
    {
        $client = static::createClient();
        $client->request(
            'OPTIONS',
            '/api/characters/me',
            server: [
                'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
            ],
        );

        self::assertResponseStatusCodeSame(204);
        $response = $client->getResponse();
        self::assertSame(self::ALLOWED_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testActualRequestFromTheActivityPreviewOriginCarriesCorsHeadersEvenWhenUnauthorized(): void
    {
        $client = static::createClient();
        // No token at all — the point is that the CORS header must be
        // present on this 401 too, or the browser never lets the frontend
        // JS see the response at all (it can't tell it apart from a CORS
        // failure), regardless of what the actual API response ends up being.
        $client->request('GET', '/api/characters/me', server: ['HTTP_ORIGIN' => self::ALLOWED_ORIGIN]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(self::ALLOWED_ORIGIN, $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRequestFromAnUntrustedOriginGetsNoCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/characters/me', server: ['HTTP_ORIGIN' => 'http://evil.example.com']);

        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Origin'), 'must never reflect an arbitrary Origin');
    }
}
