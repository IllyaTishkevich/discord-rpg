<?php

namespace App\Controller;

use App\Battle\AbilityChoice;
use App\Entity\Battle;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\AbilityType;
use App\Exception\AbilityNotAvailableException;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientActionPointsException;
use App\Exception\InsufficientEnergyException;
use App\Exception\InvalidBattleStateException;
use App\Exception\InvalidExchangeMoveException;
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
    public function show(Battle $battle, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);

        // Lazily applies the move-timeout check — see
        // BattleService::syncExchangeState()'s docblock — so a polling PvP
        // opponent sees progress even if the other side never sends another
        // request of their own, and (belt-and-suspenders) a PvE battle
        // doesn't stay stuck forever if the Activity's own client-side
        // countdown somehow never got to auto-pass it.
        $battleService->syncExchangeState($battle);

        if ($battle->isPvp()) {
            return $this->json([
                ...$this->serializer->battleForViewer($battle, $viewerIsOpponentSide),
                'exchange' => $battle->hasPendingThrow()
                    ? $this->serializer->throwResultForViewer($battleService->currentThrowResult($battle), $viewerIsOpponentSide)
                    : null,
            ]);
        }

        return $this->json([
            ...$this->serializer->battle($battle),
            // Lets the Activity's turn timer safely re-sync bit-level state
            // (faces/used/turn) after auto-resolving a stale PvE move via
            // syncExchangeState() above, the same way it already does for a
            // polling PvP viewer — see ArenaScreen.tsx's TurnTimer onExpire.
            'exchange' => $battle->hasPendingThrow()
                ? $this->serializer->throwResult($battleService->currentThrowResult($battle))
                : null,
        ]);
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
            return $this->json([
                ...$this->serializer->throwResultForViewer($result, $viewerIsOpponentSide),
                // Carries the freshly-set roundDeadlineAt — throwRound() is
                // the only place that sets it besides submitExchangeMove()
                // (whose own response already includes this), and this
                // response otherwise has nowhere else to put it.
                'battle' => $this->serializer->battleForViewer($battle, $viewerIsOpponentSide),
            ]);
        }

        return $this->json([
            ...$this->serializer->throwResult($result),
            'battle' => $this->serializer->battle($battle),
        ]);
    }

    /**
     * Interactive step-by-step flow (docs/COMBAT_V2_DESIGN.md §7-8), for
     * PvE/event and PvP alike. Submits one lead-or-respond decision — the
     * server infers which from the battle's own pending state, never
     * trusting the client's idea of whose turn it is. Body:
     * `{indices: number[], ability?: string, targets?: number[]}`; an
     * empty `indices` means "pass" and is only valid when responding to an
     * incoming attack (or, PvP-only, declining to lead — see
     * BattleService::submitPvpExchangeMove()).
     */
    #[Route('/{id}/exchanges/move', name: 'battle_submit_exchange_move', methods: ['POST'])]
    public function submitExchangeMove(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);
        $viewer = $viewerIsOpponentSide ? $battle->getOpponentCharacter() : $battle->getCharacter();

        $body = $this->decodeJson($request);
        $indices = $body['indices'] ?? [];
        if (!\is_array($indices)) {
            return $this->json(['error' => '"indices" must be an array of bit indices.'], 400);
        }
        $indices = array_map('intval', $indices);

        $choice = $this->decodeAbilityChoice($request);
        if (null === $choice) {
            return $this->json(['error' => 'Invalid "ability" — must be one of: '.implode(', ', array_column(AbilityType::cases(), 'value'))], 400);
        }

        try {
            $result = $battleService->submitExchangeMove($battle, $viewer, $indices, $choice);
        } catch (NoPendingThrowException) {
            return $this->json(['error' => 'Call /throw before submitting a move.'], 409);
        } catch (InvalidExchangeMoveException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (AbilityNotAvailableException|InsufficientActionPointsException|InvalidBattleStateException|BattleAlreadyFinishedException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        if (!$battle->isPvp()) {
            if ($result->roundComplete) {
                return $this->json([
                    'roundComplete' => true,
                    'newExchanges' => $result->newExchanges,
                    'playerFaces' => $result->playerFaces,
                    'opponentFaces' => $result->opponentFaces,
                    'playerUsed' => $result->playerUsed,
                    'opponentUsed' => $result->opponentUsed,
                    'playerMultipliers' => $result->playerMultipliers,
                    'opponentMultipliers' => $result->opponentMultipliers,
                    'round' => $this->serializer->round($result->round),
                    'battle' => $this->serializer->battle($battle),
                ]);
            }

            return $this->json([
                'roundComplete' => false,
                'newExchanges' => $result->newExchanges,
                'playerFaces' => $result->playerFaces,
                'opponentFaces' => $result->opponentFaces,
                'playerUsed' => $result->playerUsed,
                'opponentUsed' => $result->opponentUsed,
                'playerMultipliers' => $result->playerMultipliers,
                'opponentMultipliers' => $result->opponentMultipliers,
                'turn' => $result->turn,
                'incomingMove' => $result->incomingMove,
                'battle' => $this->serializer->battle($battle),
            ]);
        }

        if ($result->roundComplete) {
            return $this->json([
                ...$this->serializer->exchangeMoveResultForViewer($result, $viewerIsOpponentSide),
                'round' => $this->serializer->roundForViewer($result->round, $viewerIsOpponentSide),
                'battle' => $this->serializer->battleForViewer($battle, $viewerIsOpponentSide),
            ]);
        }

        return $this->json([
            ...$this->serializer->exchangeMoveResultForViewer($result, $viewerIsOpponentSide),
            'battle' => $this->serializer->battleForViewer($battle, $viewerIsOpponentSide),
        ]);
    }

    /**
     * PvP only: the most recently resolved round — for the side that was
     * waiting on the other player, this is how they learn what happened
     * (the resolving request's response went to whoever submitted second).
     */
    #[Route('/{id}/rounds/latest', name: 'battle_latest_round', methods: ['GET'])]
    public function latestRound(Battle $battle): JsonResponse
    {
        $viewerIsOpponentSide = $this->requireParticipantSide($battle);

        $latest = $battle->getRounds()->last();
        if (false === $latest) {
            return $this->json(null);
        }

        return $this->json($battle->isPvp()
            ? $this->serializer->roundForViewer($latest, $viewerIsOpponentSide)
            : $this->serializer->round($latest));
    }

    /**
     * @return AbilityChoice|null null if "ability" was present but not a
     *                            recognized AbilityType value (400 case);
     *                            omitted entirely defaults to Flip
     */
    private function decodeAbilityChoice(Request $request): ?AbilityChoice
    {
        $body = $this->decodeJson($request);

        $abilityValue = $body['ability'] ?? AbilityType::Flip->value;
        if (!\is_string($abilityValue) || null === ($ability = AbilityType::tryFrom($abilityValue))) {
            return null;
        }

        $targets = $body['targets'] ?? $body['actionTargets'] ?? [];
        if (!\is_array($targets)) {
            $targets = [];
        }

        return new AbilityChoice($ability, array_map('intval', $targets));
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
