<?php

namespace App\Controller;

use App\Entity\Battle;
use App\Entity\Character;
use App\Entity\User;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Exception\InvalidBattleStateException;
use App\Exception\NoPendingThrowException;
use App\Repository\BattleRepository;
use App\Serializer\BattleSerializer;
use App\Service\BattleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/battles')]
class BattleController extends AbstractApiController
{
    public function __construct(private readonly BattleSerializer $serializer)
    {
    }

    #[Route('/pve', name: 'battle_start_pve', methods: ['POST'])]
    public function startPve(BattleService $battleService): JsonResponse
    {
        $character = $this->requireCharacter();

        try {
            $battle = $battleService->startPveBattle($character);
        } catch (InsufficientEnergyException) {
            return $this->json(['error' => 'Not enough energy to start a battle.'], 409);
        }

        return $this->json($this->serializer->battle($battle), 201);
    }

    /**
     * Polled by the Activity right after login to decide whether to show
     * the duel lobby / arena instead of the profile screen.
     */
    #[Route('/my-active-pvp', name: 'battle_my_active_pvp', methods: ['GET'])]
    public function myActivePvp(BattleRepository $battleRepository): JsonResponse
    {
        $character = $this->requireCharacter();
        $battle = $battleRepository->findActivePvpFor($character);

        if (null === $battle) {
            return $this->json(null);
        }

        return $this->json($this->serializer->battleForViewer($battle, $battle->getOpponentCharacter() === $character));
    }

    #[Route('/{id}', name: 'battle_show', methods: ['GET'])]
    public function show(Battle $battle): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);

        if ($battle->isPvp()) {
            return $this->json($this->serializer->battleForViewer($battle, $viewerIsOpponentSide));
        }

        return $this->json($this->serializer->battle($battle));
    }

    /**
     * PvP only: accept the challenge (as the invited side) and/or mark your
     * side ready to fight. Once both sides are ready, the battle flips to
     * in_progress and the first round is thrown automatically.
     */
    #[Route('/{id}/join', name: 'battle_join_pvp', methods: ['POST'])]
    public function join(Battle $battle, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);
        if (!$battle->isPvp()) {
            return $this->json(['error' => 'Only PvP battles have a join step.'], 400);
        }

        try {
            if ($viewerIsOpponentSide && !$battle->isOpponentAccepted()) {
                $battleService->acceptPvpChallenge($battle);
            }
            $battleService->markReady($battle, $viewerIsOpponentSide ? $battle->getOpponentCharacter() : $battle->getCharacter());
        } catch (InvalidBattleStateException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json($this->serializer->battleForViewer($battle, $viewerIsOpponentSide));
    }

    #[Route('/{id}/decline', name: 'battle_decline_pvp', methods: ['POST'])]
    public function decline(Battle $battle, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);
        if (!$battle->isPvp()) {
            return $this->json(['error' => 'Only PvP battles can be declined.'], 400);
        }

        try {
            $battleService->declinePvpChallenge($battle);
        } catch (InvalidBattleStateException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json($this->serializer->battleForViewer($battle, $viewerIsOpponentSide));
    }

    #[Route('/{id}/throw', name: 'battle_throw_round', methods: ['POST'])]
    public function throwRound(Battle $battle, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);

        try {
            $result = $battleService->throwRound($battle);
        } catch (BattleAlreadyFinishedException) {
            return $this->json(['error' => 'This battle has already finished.'], 409);
        }

        if ($battle->isPvp()) {
            return $this->json($this->serializer->throwResultForViewer($result, $viewerIsOpponentSide));
        }

        return $this->json($this->serializer->throwResult($result));
    }

    /**
     * PvE/event only. PvP rounds go through /submit-actions instead, since
     * damage can't be computed until both real sides have chosen.
     */
    #[Route('/{id}/resolve', name: 'battle_resolve_round', methods: ['POST'])]
    public function resolveRound(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        $this->requireParticipantSide($battle);

        $actionTargets = $this->decodeJson($request)['actionTargets'] ?? [];
        if (!\is_array($actionTargets)) {
            return $this->json(['error' => '"actionTargets" must be an array of indices.'], 400);
        }

        try {
            $round = $battleService->resolveRound($battle, array_map('intval', $actionTargets));
        } catch (NoPendingThrowException) {
            return $this->json(['error' => 'Call /throw before /resolve.'], 409);
        } catch (InvalidBattleStateException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'round' => $this->serializer->round($round),
            'battle' => $this->serializer->battle($battle),
        ]);
    }

    /**
     * PvP only: submit your action-flip targets for the current round. The
     * round only resolves once both sides have submitted — until then this
     * returns `{waitingForOpponent: true}` and the client should keep
     * polling GET /{id} for `opponentSubmitted`.
     */
    #[Route('/{id}/submit-actions', name: 'battle_submit_actions', methods: ['POST'])]
    public function submitActions(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);
        $viewerCharacter = $viewerIsOpponentSide ? $battle->getOpponentCharacter() : $battle->getCharacter();

        $actionTargets = $this->decodeJson($request)['actionTargets'] ?? [];
        if (!\is_array($actionTargets)) {
            return $this->json(['error' => '"actionTargets" must be an array of indices.'], 400);
        }

        try {
            $round = $battleService->submitActions($battle, $viewerCharacter, array_map('intval', $actionTargets));
        } catch (NoPendingThrowException) {
            return $this->json(['error' => 'Call /throw before /submit-actions.'], 409);
        } catch (InvalidBattleStateException|BattleAlreadyFinishedException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        if (null === $round) {
            return $this->json(['waitingForOpponent' => true]);
        }

        return $this->json([
            'round' => $this->serializer->roundForViewer($round, $viewerIsOpponentSide),
            'battle' => $this->serializer->battleForViewer($battle, $viewerIsOpponentSide),
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

    /**
     * @return bool whether the current user is battle.opponentCharacter (as
     *              opposed to battle.character) — always false for PvE/event
     */
    private function requireParticipantSide(Battle $battle): bool
    {
        $character = $this->requireCharacter();

        if ($battle->getCharacter() === $character) {
            return false;
        }
        if ($battle->getOpponentCharacter() === $character) {
            return true;
        }

        throw $this->createAccessDeniedException();
    }
}
