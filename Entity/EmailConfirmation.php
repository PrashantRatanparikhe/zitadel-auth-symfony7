<?php

namespace App\Entity\Service;

use ApiPlatform\Action\NotFoundAction;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Service\Traits\TimestampableEntity;
use App\Entity\Service\UserAlumni;
use App\Repository\Service\EmailConfirmationRepository;
use App\State\Processor\EmailConfirmation\EmailConfirmationConfirm;
use App\State\Processor\EmailConfirmation\EmailConfirmationCreate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Validator as AppAssert;

#[ORM\Entity(repositoryClass: EmailConfirmationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    types: ['EmailConfirmation'],
    operations: [
        new Get(
            controller: NotFoundAction::class,
            output: false,
            read: false
        ),
        new Post(
            denormalizationContext: ['groups' => 'email_confirmation:post'],
            processor: EmailConfirmationCreate::class
        ),
        new Patch(
            uriTemplate: '/email_confirmations/{uuid}/confirm',
            normalizationContext: ['groups' => 'email_confirmation:read'],
            denormalizationContext: ['groups' => 'email_confirmation:confirm'],
            processor: EmailConfirmationConfirm::class
        ),
    ],
    normalizationContext: ['groups' => ['email_confirmation:read']],
    denormalizationContext: ['groups' => ['email_confirmation:post']],
    order: ['createdAt' => 'DESC'],
)]
#[AppAssert\Constraints\EmailConfirmation\EmailRegister(groups: [
    'entity:check:email',
])]
class EmailConfirmation
{
    use TimestampableEntity;

    #[ApiProperty(identifier: true)]
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['email_confirmation:read','user:registration-read'])]
    private ?Uuid $uuid;

    #[ORM\Column(length: 255)]
    #[Groups(['email_confirmation:post'])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['email_confirmation:confirm'])]
    private ?string $confirmToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\ManyToOne(targetEntity: UserAlumni::class)]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: true, onDelete: 'CASCADE')]
    private ?UserAlumni $userAlumni = null;

    #[Groups(['email_confirmation:confirm'])]
    private ?string $userUuid = null;

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getConfirmToken(): ?string
    {
        return $this->confirmToken;
    }

    public function setConfirmToken(?string $confirmToken): static
    {
        $this->confirmToken = $confirmToken;

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function getUserAlumni(): ?UserAlumni
    {
        return $this->userAlumni;
    }

    public function setUserAlumni(?UserAlumni $userAlumni): static
    {
        $this->userAlumni = $userAlumni;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getUserUuid()
    {
        return $this->userUuid;
    }

    public function setUserUuid($userUuid): self
    {
        $this->userUuid = $userUuid;

        return $this;
    }
}
