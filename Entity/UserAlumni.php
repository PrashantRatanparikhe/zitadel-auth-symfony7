<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Controller\User\UserChecker;
use App\Controller\User\UserAdminChecker;
use App\Controller\User\UserGetByEmailController;
use App\Controller\User\UserGetController;
use App\Controller\User\UserSearchController;
use App\Controller\User\UserWithLastAttendeesController;
use App\Entity\Service\Traits\TimestampableEntity;
use App\Repository\Service\UserAlumniRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class UserAlumni
 * 
 * Represents a user alumni entity with various properties and methods for managing user data.
 */
#[ApiResource(
    types: ['UserAlumni'],
    operations: [
        new Get(),
        new GetCollection(),
        new Patch(
            uriTemplate: '/user_alumnis/{uuid}/deactivate',
            denormalizationContext: ['groups' => ['user:patch-deactivate']],
            security: 'is_granted(\'DEACTIVATE\', object)',
            processor: DeactivateUser::class
        ),
        new Get(
            uriTemplate: '/user-checker',
            controller: UserChecker::class,
        ),
        new GetCollection(
            uriTemplate: '/users/search/{uuid}',
            controller: UserGetController::class,
        ),
        new Post(
            uriTemplate: '/user_alumnis/registration',
            normalizationContext: ['groups' => ['user:read', 'user:registration-read']],
            denormalizationContext: ['groups' => ['user:registration-post']],
            processor: RegistrationProcessor::class
        ),
        new Post(
            uriTemplate: '/user_alumnis/registration_plain',
            normalizationContext: ['groups' => ['user:read', 'user:registration-read']],
            denormalizationContext: ['groups' => ['user:registration-post']],
            processor: RegistrationPlainProcessor::class
        ),
        new GetCollection(
            name: 'search_by_name',
            uriTemplate: '/users/search',
            controller: UserSearchController::class,
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'search',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ]
        ),
        new Get(
            name: 'search_by_email',
            uriTemplate: '/users/search_by_email',
            controller: UserGetByEmailController::class,
            read: false,
        ),
        new Get(
            uriTemplate: '/user-admin-checker',
            controller: UserAdminChecker::class,
            read: false,
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:post', 'user:registration-post']],
    processor: UserProcessor::class
)]
#[ApiFilter(SearchFilter::class, properties: ['legacyId' => 'exact', 'uuid' => 'exact', 'email' => 'exact'])]
#[ORM\Entity(repositoryClass: UserAlumniRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'user_alumni')]
#[UniqueEntity('legacyId')]
#[UniqueEntity('email')]
#[Groups(['cqrs_export_user_alumni'])]
class UserAlumni implements UserInterface, PasswordAuthenticatedUserInterface
{
    use SoftDeleteableEntity;
    use TimestampableEntity;

    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_DEFAULT = 'ROLE_USER';

    public const APPROVAL_STATUS_PENDING = 'Pending';
    public const APPROVAL_STATUS_APPROVED = 'Approved';
    public const APPROVAL_STATUS_DENIED = 'Denied';

    #[ApiProperty(identifier: true)]
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['user:read', 'profile:read', 'cqrs_export_user_alumni_basic', 'mv:serialize'])]
    private ?Uuid $uuid = null;

    #[Assert\Email(message: "The email '{{ value }}' is not a valid email.")]
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Groups(['user:read', 'user:post', 'profile:read', 'user:registration-post', 'cqrs_export_user_alumni_basic', 'user_registration_request:read', 'mv:serialize'])]
    private ?string $email = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $password = null;

    #[Assert\Regex(pattern: '/[0-9A-Za-z!@#$%]{6,}/', message: 'Password must be 6 characters long and can contain numbers, letters, or one of the following: !@#$%', groups: ['user:registration-post'])]
    #[Assert\Length(min: 6, max: 255, minMessage: 'Password must be {{ limit }} characters or more', maxMessage: 'Password must be {{ limit }} characters or less', groups: ['user:registration-post'])]
    #[Groups(['user:registration-post'])]
    private ?string $plainPassword = null;

    #[Groups(['put-change-password'])]
    #[Assert\NotBlank(message: 'Password is required', normalizer: 'trim', groups: ['put-change-password'])]
    #[Assert\Regex(pattern: '/[0-9A-Za-z!@#$%]{6,}/', message: 'Password must be 6 characters long and can contain numbers, letters, or one of the following: !@#$%', groups: ['put-change-password'])]
    #[Assert\Length(min: 6, max: 255, minMessage: 'Password must be {{ limit }} characters or more', maxMessage: 'Password must be {{ limit }} characters or less')]
    private ?string $newPassword = null;

    /**
     * @UserPassword(groups={"put-change-password"})
     */
    #[Groups(['put-change-password'])]
    #[Assert\NotBlank(groups: ['put-change-password'])]
    private ?string $oldPassword = null;

    #[ORM\Column(type: 'boolean', options: ['comment' => '0 - will make the user unable to login'])]
    #[Groups(['user:read', 'profile:read', 'user:patch-deactivate'])]
    private bool $enabled = false;

    #[ORM\Column(type: 'boolean', options: ['comment' => 'Email address is confirmed (1 or 0)'])]
    #[Groups(['user:read', 'profile:read'])]
    private bool $confirmed = false;

    #[Assert\Range(min: 0, max: 2, notInRangeMessage: 'Approved must be between {{ min }} and {{ max }} to enter')]
    #[ORM\Column(type: 'smallint', options: ['comment' => 'for closed community:  0 - pending;  1 - user approved; 2 - rejected;  [We also have ROLE_USER_PENDING]'])]
    #[Groups(['user:read', 'profile:read'])]
    private int $approved = 1;

    #[ORM\Column(type: 'boolean', options: ['comment' => 'email marketing sent:  0 - subscribed;  1 - unsubscribed;'])]
    private bool $unsubscribed = false;

    #[ORM\Column(type: 'boolean', options: ['comment' => 'Primary client admin (1 or 0)'])]
    #[Groups(['profile:read'])]
    private bool $isPrimary = false;

    #[Assert\NotBlank(groups: ['user:registration-post'])]
    #[Assert\IsTrue(groups: ['user:registration-post'])]
    #[ORM\Column(type: 'boolean', options: ['default' => 0, 'comment' => '1 if the user has accepted T&C'])]
    #[Groups(['user:registration-post', 'profile:read'])]
    private bool $termsAndConditions = false;

    #[Groups(['user:registration-post'])]
    private ?string $firstName = null;

    #[Groups(['user:registration-post'])]
    private ?string $lastName = null;

    #[ORM\Column(name: 'zitadel_user_id', type: Types::BIGINT, nullable: true)]
    private ?string $zitadelUserId = null;

    #[ORM\Column(name: 'zitadel_sync_error', length: 255, nullable: true)]
    private ?string $zitadelSyncError = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetime', nullable: true)]
    #[Groups(['profile:read'])]
    private ?\DateTimeInterface $lastLoginAt = null;

    public function __construct()
    {
        // Initialize any necessary properties here
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getNewPassword(): ?string
    {
        return $this->newPassword;
    }

    public function setNewPassword(string $newPassword): self
    {
        $this->newPassword = $newPassword;
        return $this;
    }

    public function getOldPassword(): ?string
    {
        return $this->oldPassword;
    }

    public function setOldPassword(?string $oldPassword): self
    {
        $this->oldPassword = $oldPassword;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getTermsAndConditions(): bool
    {
        return $this->termsAndConditions;
    }

    public function setTermsAndConditions(bool $termsAndConditions): self
    {
        $this->termsAndConditions = $termsAndConditions;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeInterface
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeInterface $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    // Additional methods for managing user activities and logs can be added here
}
