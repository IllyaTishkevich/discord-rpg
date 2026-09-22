<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\User;
use App\Exception\AlreadyRegisteredException;
use App\Repository\TournamentRepository;
use App\Service\TournamentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tournaments')]
class TournamentController extends AbstractApiController
{
    #[Route('/open', name: 'tournament_open', methods: ['GET'])]
    public function open(TournamentRepository $tournamentRepository): JsonResponse
    {
        $tournament = $tournamentRepository->findOpenForRegistration();

        return $this->json(null === $tournament ? null : $this->serializeTournament($tournament));
    }

    #[Route('/latest', name: 'tournament_latest', methods: ['GET'])]
    public function latest(TournamentRepository $tournamentRepository): JsonResponse
    {
        $tournament = $tournamentRepository->findMostRecent();

        return $this->json(null === $tournament ? null : $this->serializeTournament($tournament));
    }

    #[Route('/{id}', name: 'tournament_show', methods: ['GET'])]
    public function show(Tournament $tournament): JsonResponse
    {
        return $this->json($this->serializeTournament($tournament));
    }

    #[Route('/{id}/register', name: 'tournament_register', methods: ['POST'])]
    public function register(Tournament $tournament, TournamentService $tournamentService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $character = $user->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        try {
            $tournamentService->register($tournament, $character);
        } catch (AlreadyRegisteredException) {
            return $this->json(['error' => 'Already registered.'], 409);
        }

        return $this->json($this->serializeTournament($tournament), 201);
    }

    private function serializeTournament(Tournament $tournament): array
    {
        return [
            'id' => $tournament->getId(),
            'status' => $tournament->getStatus()->value,
            'entryCount' => $tournament->getEntries()->count(),
            'champion' => $tournament->getChampionCharacter()?->getCharacterClass()->getName(),
            'matches' => array_map(
                fn (TournamentMatch $match) => [
                    'round' => $match->getRoundNumber(),
                    'slot' => $match->getSlot(),
                    'characterA' => $this->characterLabel($match->getCharacterA()),
                    'characterB' => $this->characterLabel($match->getCharacterB()),
                    'winner' => $this->characterLabel($match->getWinner()),
                    'isBye' => $match->isBye(),
                ],
                $tournament->getMatches()->toArray(),
            ),
        ];
    }

    private function characterLabel(?Character $character): ?string
    {
        return $character?->getUser()->getDisplayName();
    }
}
