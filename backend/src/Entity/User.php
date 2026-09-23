<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $discordId;

    // Named displayName (not "username") so it can't collide with Symfony's
    // legacy getUsername()/PropertyAccessor("username") identity lookup —
    // getUserIdentifier() below is the only identity accessor.
    #[ORM\Column(length: 64)]
    private string $displayName;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Character::class, cascade: ['persist', 'remove'])]
    private ?Character $character = null;

    public function __construct(string $discordId, string $displayName, ?string $avatar = null)
    {
        $this->discordId = $discordId;
        $this->displayName = $displayName;
        $this->avatar = $avatar;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDiscordId(): string
    {
        return $this->discordId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    /**
     * Ready-to-use Discord CDN URL, or null if this user never set an
     * avatar (Discord's own default-avatar fallback isn't reproduced here —
     * callers show their own placeholder instead, same as every other
     * "icon if there is one" spot in this app — see CharacterController and
     * BattleSerializer, the two current callers).
     */
    public function getAvatarUrl(): ?string
    {
        return null !== $this->avatar
            ? \sprintf('https://cdn.discordapp.com/avatars/%s/%s.png', $this->discordId, $this->avatar)
            : null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->discordId;
    }
}
