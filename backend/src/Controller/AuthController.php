<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\DiscordOAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractApiController
{
    #[Route('/discord/callback', name: 'auth_discord_callback', methods: ['POST'])]
    public function discordCallback(
        Request $request,
        DiscordOAuthClient $discordOAuthClient,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        JWTTokenManagerInterface $jwtManager,
    ): JsonResponse {
        $code = $this->decodeJson($request)['code'] ?? null;
        if (!\is_string($code) || '' === $code) {
            return $this->json(['error' => 'Missing "code" parameter.'], 400);
        }

        $profile = $discordOAuthClient->fetchProfileForCode($code);

        $user = $userRepository->findOneByDiscordId($profile['id']);
        if (null === $user) {
            $user = new User($profile['id'], $profile['username'], $profile['avatar']);
            $entityManager->persist($user);
        } else {
            $user->setDisplayName($profile['username']);
            $user->setAvatar($profile['avatar']);
        }
        $entityManager->flush();

        return $this->json([
            'token' => $jwtManager->create($user),
            'user' => [
                'id' => $user->getId(),
                'discordId' => $user->getDiscordId(),
                'username' => $user->getDisplayName(),
                'avatar' => $user->getAvatar(),
                'hasCharacter' => null !== $user->getCharacter(),
            ],
        ]);
    }
}
