<?php

namespace App\Service;

use App\Entity\Character;
use App\Repository\BattleRepository;
use App\Repository\BattleRoundRepository;
use App\Repository\CharacterEquipmentRepository;
use App\Repository\CharacterQuestProgressRepository;
use App\Repository\TournamentEntryRepository;
use App\Repository\TournamentMatchRepository;
use App\Repository\TournamentRepository;
use Doctrine\ORM\EntityManagerInterface;

class CharacterService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BattleRepository $battleRepository,
        private readonly BattleRoundRepository $battleRoundRepository,
        private readonly TournamentMatchRepository $tournamentMatchRepository,
        private readonly TournamentEntryRepository $tournamentEntryRepository,
        private readonly TournamentRepository $tournamentRepository,
        private readonly CharacterQuestProgressRepository $questProgressRepository,
        private readonly CharacterEquipmentRepository $equipmentRepository,
    ) {
    }

    /**
     * Deletes a character and everything elsewhere in the schema that
     * references it — most of those foreign keys are NO ACTION (see the
     * migrations), so a plain remove() on the Character alone fails the
     * moment it has ever fought a battle, joined a tournament, bought
     * equipment, or made quest progress, which is to say almost always.
     * Leaves the User itself untouched, free to create a fresh Character.
     *
     * A tournament bracket the character wasn't personally part of is left
     * alone; only its own match slots/entries disappear (which also erases
     * the opponent's record of that one match — an accepted trade-off for a
     * "wipe my character" command), and a tournament it won loses its
     * championCharacter reference rather than being deleted outright.
     *
     * Each battle's own BattleRound rows are deleted explicitly rather than
     * relying on Battle::$rounds' orphanRemoval — that cascade only fires
     * off a genuinely DB-hydrated collection, and a Battle entity still
     * sitting in the identity map from earlier in the same request (as in
     * BotControllerWipeTest's fixture setup) keeps whatever plain, possibly
     * empty Collection its constructor made instead. character_bit/
     * character_ability (join tables) and CharacterInventoryItem are left
     * for the DB's ON DELETE CASCADE / Character::$inventoryItems'
     * orphanRemoval to clean up when the Character itself is removed below.
     */
    public function wipeCharacter(Character $character): void
    {
        foreach ($this->tournamentRepository->findAllByChampionCharacter($character) as $tournament) {
            $tournament->forgetChampion();
        }

        foreach ($this->tournamentMatchRepository->findAllFor($character) as $match) {
            $this->entityManager->remove($match);
        }

        foreach ($this->tournamentEntryRepository->findByCharacter($character) as $entry) {
            $this->entityManager->remove($entry);
        }

        foreach ($this->questProgressRepository->findByCharacter($character) as $progress) {
            $this->entityManager->remove($progress);
        }

        foreach ($this->equipmentRepository->findByCharacter($character) as $equipment) {
            $this->entityManager->remove($equipment);
        }

        foreach ($this->battleRepository->findAllFor($character) as $battle) {
            foreach ($this->battleRoundRepository->findByBattle($battle) as $round) {
                $this->entityManager->remove($round);
            }
            $this->entityManager->remove($battle);
        }

        $character->getUser()->setCharacter(null);
        $this->entityManager->remove($character);
        $this->entityManager->flush();
    }
}
