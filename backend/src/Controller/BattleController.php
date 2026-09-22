<?php

namespace App\Controller;

use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\BattleStatus;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Exception\NoPendingThrowException;
use App\Service\BattleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/battles')]
class BattleController extends AbstractApiController
{
    #[Route('/pve', name: 'battle_start_pve', methods: ['POST'])]
    public function startPve(BattleService $battleService): JsonResponse
    {
        $character = $this->requireCharacter();

        try {
            $battle = $battleService->startPveBattle($character);
        } catch (InsufficientEnergyException) {
            return $this->json(['error' => 'Not enough energy to start a battle.'], 409);
        }

        return $this->json($this->serializeBattle($battle), 201);
    }

    #[Route('/{id}', name: 'battle_show', methods: ['GET'])]
    public function show(Battle $battle): JsonResponse
    {
        $this->assertOwnership($battle);

        return $this->json($this->serializeBattle($battle));
    }

    #[Route('/{id}/throw', name: 'battle_throw_round', methods: ['POST'])]
    public function throwRound(Battle $battle, BattleService $battleService): JsonResponse
    {
        $this->assertOwnership($battle);

        try {
            $result = $battleService->throwRound($battle);
        } catch (BattleAlreadyFinishedException) {
            return $this->json(['error' => 'This battle has already finished.'], 409);
        }

        return $this->json($this->serializeThrow($result));
    }

    #[Route('/{id}/resolve', name: 'battle_resolve_round', methods: ['POST'])]
    public function resolveRound(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        $this->assertOwnership($battle);

        $actionTargets = $this->decodeJson($request)['actionTargets'] ?? [];
        if (!\is_array($actionTargets)) {
            return $this->json(['error' => '"actionTargets" must be an array of indices.'], 400);
        }

        try {
            $round = $battleService->resolveRound($battle, array_map('intval', $actionTargets));
        } catch (NoPendingThrowException) {
            return $this->json(['error' => 'Call /throw before /resolve.'], 409);
        }

        return $this->json([
            'round' => $this->serializeRound($round),
            'battle' => $this->serializeBattle($battle),
        ]);
    }

    private function requireCharacter(): Character
    {
        /** @var User $user */
        $user = $this->getUser();
        $character = $user->getCharacter();
        if (null === $character) {
            throw $this->createNotFoundException('No character for this user yet.');
        }

        return $character;
    }

    private function assertOwnership(Battle $battle): void
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($battle->getCharacter()->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function serializeBattle(Battle $battle): array
    {
        return [
            'id' => $battle->getId(),
            'status' => $battle->getStatus()->value,
            'roundNumber' => $battle->getRoundNumber(),
            'hasPendingThrow' => $battle->hasPendingThrow(),
            'opponent' => [
                'name' => $battle->getOpponentName(),
                'hp' => $battle->getOpponentHp(),
                'maxHp' => $battle->getOpponentMaxHp(),
            ],
            'character' => [
                'hp' => $battle->getCharacter()->getHp(),
                'maxHp' => $battle->getCharacter()->getMaxHp(),
            ],
            'rewards' => BattleStatus::Won === $battle->getStatus()
                ? ['xp' => BattleService::XP_REWARD, 'coins' => BattleService::COIN_REWARD]
                : null,
        ];
    }

    private function serializeThrow(ThrowResult $result): array
    {
        return [
            'playerFaces' => array_map(static fn ($t) => $t->thrownFace->value, $result->playerThrows),
            'opponentFaces' => array_map(static fn ($t) => $t->thrownFace->value, $result->opponentThrows),
            'playerActionCount' => $result->playerActionCount,
        ];
    }

    private function serializeRound(BattleRound $round): array
    {
        return [
            'roundNumber' => $round->getRoundNumber(),
            'playerFaces' => $round->getPlayerFaces(),
            'opponentFaces' => $round->getOpponentFaces(),
            'damageToOpponent' => $round->getDamageToOpponent(),
            'damageToPlayer' => $round->getDamageToPlayer(),
        ];
    }
}
