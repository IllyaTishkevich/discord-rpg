<?php

namespace App\Service;

use App\Battle\AbilityChoice;
use App\Battle\BitThrow;
use App\Battle\ExchangeResolver;
use App\Entity\Character;
use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use App\Entity\TournamentMatch;
use App\Enum\TournamentStatus;
use App\Exception\AlreadyRegisteredException;
use App\Exception\NotEnoughEntriesException;
use App\Repository\TournamentEntryRepository;
use App\Repository\TournamentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single-elimination bracket. There is no live PvP battle engine yet, so
 * start() simulates every match server-side from both characters' bits the
 * moment the bracket starts, all the way to a champion, in one call.
 */
class TournamentService
{
    private const MIN_ENTRIES = 2;
    private const MAX_SIMULATED_ROUNDS = 30;
    public const CHAMPION_XP_REWARD = 50;
    public const CHAMPION_COIN_REWARD = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TournamentRepository $tournamentRepository,
        private readonly TournamentEntryRepository $entryRepository,
        private readonly ExchangeResolver $exchangeResolver,
    ) {
    }

    /**
     * Idempotent: returns the already-open tournament if one exists, so
     * repeated admin triggers don't spawn duplicate brackets.
     */
    public function openRegistration(): Tournament
    {
        $existing = $this->tournamentRepository->findOpenForRegistration();
        if (null !== $existing) {
            return $existing;
        }

        $tournament = new Tournament();
        $this->entityManager->persist($tournament);
        $this->entityManager->flush();

        return $tournament;
    }

    public function register(Tournament $tournament, Character $character): TournamentEntry
    {
        if (null !== $this->entryRepository->findOneByTournamentAndCharacter($tournament, $character)) {
            throw new AlreadyRegisteredException('Character is already registered for this tournament.');
        }

        $entry = new TournamentEntry($tournament, $character);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    public function start(Tournament $tournament): Character
    {
        $characters = array_map(static fn (TournamentEntry $e) => $e->getCharacter(), $tournament->getEntries()->toArray());

        if (\count($characters) < self::MIN_ENTRIES) {
            throw new NotEnoughEntriesException(sprintf('Need at least %d entries to start.', self::MIN_ENTRIES));
        }

        shuffle($characters);
        $bracketSize = 2 ** (int) ceil(log(\count($characters), 2));
        /** @var (Character|null)[] $round */
        $round = array_pad($characters, $bracketSize, null);

        $roundNumber = 1;
        while (\count($round) > 1) {
            $nextRound = [];
            for ($slot = 0; $slot < \count($round); $slot += 2) {
                $a = $round[$slot];
                $b = $round[$slot + 1];
                $winner = match (true) {
                    null === $a => $b,
                    null === $b => $a,
                    default => $this->simulateMatch($a, $b),
                };

                $this->entityManager->persist(new TournamentMatch($tournament, $roundNumber, $slot / 2, $a, $b, $winner));
                $nextRound[] = $winner;
            }
            $round = $nextRound;
            ++$roundNumber;
        }

        $champion = $round[0];
        $champion->addXp(self::CHAMPION_XP_REWARD);
        $champion->addCoins(self::CHAMPION_COIN_REWARD);
        $tournament->finish($champion);

        $this->entityManager->flush();

        return $champion;
    }

    /**
     * Symmetric simulated match: both real characters here are driven by
     * ExchangeResolver's built-in heuristic on both sides (Flip is the only
     * ability either "player" can use), since neither is an interactive
     * player in this simulation — same engine as PvE/event auto-play,
     * see docs/COMBAT_V2_DESIGN.md.
     */
    private function simulateMatch(Character $a, Character $b): Character
    {
        $aBits = $a->getAllBits();
        $bBits = $b->getAllBits();
        $aHp = $a->getMaxHp();
        $bHp = $b->getMaxHp();

        for ($round = 0; $round < self::MAX_SIMULATED_ROUNDS; ++$round) {
            $aThrows = array_map(static fn ($bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB()), $aBits);
            $bThrows = array_map(static fn ($bit) => BitThrow::random($bit->getFaceA(), $bit->getFaceB(), $bit->hasAdvantageA(), $bit->hasAdvantageB()), $bBits);

            $result = $this->exchangeResolver->resolveRound($aThrows, $bThrows, AbilityChoice::flip(), AbilityChoice::flip());
            $aHp -= $result->damageToPlayer;
            $bHp -= $result->damageToOpponent;

            if ($aHp <= 0 || $bHp <= 0) {
                break;
            }
        }

        if ($aHp <= 0 && $bHp > 0) {
            return $b;
        }
        if ($bHp <= 0 && $aHp > 0) {
            return $a;
        }

        // Round cap hit, or a simultaneous knockout — decide by remaining HP.
        return $aHp >= $bHp ? $a : $b;
    }
}
