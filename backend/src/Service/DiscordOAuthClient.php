<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exchanges a Discord OAuth2 authorization code for an access token and
 * fetches the corresponding Discord user profile.
 *
 * The code comes from the Discord Embedded App SDK's `authorize` command
 * (run inside the Activity iframe), which — unlike a normal web OAuth2
 * flow — has no redirect step, so no redirect_uri is sent here either.
 */
class DiscordOAuthClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'DISCORD_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'DISCORD_CLIENT_SECRET')] private readonly string $clientSecret,
    ) {
    }

    /**
     * @return array{id: string, username: string, avatar: ?string, accessToken: string}
     */
    public function fetchProfileForCode(string $code): array
    {
        $tokenResponse = $this->httpClient->request('POST', 'https://discord.com/api/oauth2/token', [
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
            ],
        ]);

        $accessToken = $tokenResponse->toArray()['access_token'];

        $profileResponse = $this->httpClient->request('GET', 'https://discord.com/api/users/@me', [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
            ],
        ]);

        $profile = $profileResponse->toArray();

        return [
            'id' => $profile['id'],
            'username' => $profile['username'],
            'avatar' => $profile['avatar'] ?? null,
            'accessToken' => $accessToken,
        ];
    }
}
