<?php

namespace App\Entity;

use App\Repository\TournamentMatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One bracket slot. characterA/characterB are null for a "bye" (odd bracket
 * padding), in which case the present side auto-advances as winner.
 */
#[ORM\Entity(repositoryClass: TournamentMatchRepository::class)]
class TournamentMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tournament::class, inversedBy: 'matches')]
    #[ORM\JoinColumn(nullable: false)]
    private Tournament $tournament;

    #[ORM\Column]
    private int $roundNumber;

    #[ORM\Column]
    private int $slot;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Character $characterA;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Character $characterB;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $winner;

    #[ORM\Column]
    private bool $isBye;

    public function __construct(Tournament $tournament, int $roundNumber, int $slot, ?Character $characterA, ?Character $characterB, Character $winner)
    {
        $this->tournament = $tournament;
        $this->roundNumber = $roundNumber;
        $this->slot = $slot;
        $this->characterA = $characterA;
        $this->characterB = $characterB;
        $this->winner = $winner;
        $this->isBye = null === $characterA || null === $characterB;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getCharacterA(): ?Character
    {
        return $this->characterA;
    }

    public function getCharacterB(): ?Character
    {
        return $this->characterB;
    }

    public function getWinner(): Character
    {
        return $this->winner;
    }

    public function isBye(): bool
    {
        return $this->isBye;
    }
}
