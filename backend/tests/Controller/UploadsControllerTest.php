<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Regression coverage for a real bug: /api is otherwise locked behind
 * IS_AUTHENTICATED_FULLY (config/packages/security.yaml) — an <img src>
 * can't carry a Bearer token, so this route specifically needs its own
 * PUBLIC_ACCESS access_control rule, easy to accidentally drop or shadow
 * when that file is edited later.
 */
class UploadsControllerTest extends WebTestCase
{
    private string $testFilePath;

    protected function setUp(): void
    {
        $this->testFilePath = \dirname(__DIR__, 2).'/public/uploads/items/phpunit-smoke-test.txt';
        file_put_contents($this->testFilePath, 'smoke test');
    }

    protected function tearDown(): void
    {
        @unlink($this->testFilePath);
        parent::tearDown();
    }

    public function testExistingUploadIsPubliclyServedWithoutAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/uploads/items/phpunit-smoke-test.txt');

        self::assertResponseIsSuccessful();
        // BinaryFileResponse::getContent() always returns false by design
        // (it streams the file rather than buffering it) — assert on the
        // resolved file instead of the body.
        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($this->testFilePath, $response->getFile()->getPathname());
    }

    public function testMissingUploadReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/uploads/items/does-not-exist.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownDirectoryIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/uploads/not-a-real-catalog/whatever.png');

        self::assertResponseStatusCodeSame(404);
    }
}
