<?php

namespace App\Entity;

use App\Enum\TournamentStatus;
use App\Repository\TournamentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-elimination bracket. Matches aren't live duels — they're
 * simulated server-side from both characters' stats/bits (via
 * ExchangeResolver, same engine as PvE/event bot auto-play) the moment the
 * bracket starts, so the whole tournament resolves in one step, not
 * match-by-match over time.
 */
#[ORM\Entity(repositoryClass: TournamentRepository::class)]
class Tournament
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: TournamentStatus::class)]
    private TournamentStatus $status;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Character $championCharacter = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: TournamentEntry::class, orphanRemoval: true)]
    private Collection $entries;

    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: TournamentMatch::class, orphanRemoval: true)]
    #[ORM\OrderBy(['roundNumber' => 'ASC', 'slot' => 'ASC'])]
    private Collection $matches;

    public function __construct()
    {
        $this->status = TournamentStatus::Registration;
        $this->createdAt = new \DateTimeImmutable();
        $this->entries = new ArrayCollection();
        $this->matches = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): TournamentStatus
    {
        return $this->status;
    }

    public function getChampionCharacter(): ?Character
    {
        return $this->championCharacter;
    }

    public function finish(Character $champion): static
    {
        $this->status = TournamentStatus::Finished;
        $this->championCharacter = $champion;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @return Collection<int, TournamentEntry>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /**
     * @return Collection<int, TournamentMatch>
     */
    public function getMatches(): Collection
    {
        return $this->matches;
    }
}
