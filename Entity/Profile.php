<?php

namespace App\Entity\Service;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Controller\Profile\ClientProfile;
use App\Controller\SingleProfile;
use App\Entity\Service\Traits\TimestampableEntity;
use App\Entity\Service\Traits\UpdatedBy;
use App\Filter\FullTextSearchFilter;
use App\Filter\IdentifierFilter;
use App\Repository\Service\ProfileRepository;
use App\State\Processor\Profile\DeactivateProfile;
use App\State\Processor\ProfileProcessor;
use App\State\ProfileProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class Profile
 *
 * Represents a user's profile in the system.
 */
#[ApiResource(
    types: ['Profile'],
    operations: [
        new GetCollection(),
        new Get(
            security: 'is_granted(\'VIEW\', object)',
            controller: SingleProfile::class
        ),
        new Delete(
            security: 'is_granted(\'DELETE\', object)'
        ),
        new Patch(
            uriTemplate: '/profiles/{uuid}/deactivate',
            denormalizationContext: ['groups' => ['profile:patch-deactivate']],
            security: 'is_granted(\'ADMIN-EDIT\', object)',
            processor: DeactivateProfile::class
        ),
        new Patch(security: 'is_granted(\'EDIT\', object)'),
        new Patch(
            uriTemplate: '/profiles/{uuid}/administration',
            normalizationContext: ['groups' => ['profile:read', 'profile:admin-read']],
            denormalizationContext: ['groups' => 'profile:admin-post'],
            security: 'is_granted(\'ADMIN-EDIT\', object)',
        ),
        new Get(
            uriTemplate: '/user_alumnis/{userUuid}/profiles.{_format}',
            uriVariables: [
                'userUuid' => new Link(toProperty: 'userAlumni', fromClass: UserAlumni::class),
            ],
        ),
        new GetCollection(uriTemplate: '/profile/me'),
        new GetCollection(uriTemplate: '/profile/client', controller: ClientProfile::class, deserialize: false, name: 'client-profile'),
        new GetCollection(
            uriTemplate: 'profile/list-limited-access',
            name: 'profiles_list_limited_access',
        ),
        new Patch(
            uriTemplate: '/profiles/{uuid}/image',
            normalizationContext: ['groups' => ['profile:read']],
            denormalizationContext: ['groups' => 'profile:image-post'],
            security: 'is_granted(\'EDIT\', object)'
        )
    ],
    normalizationContext: ['groups' => ['profile:read','profile:basic-read', 'datetime:read', 'contact_email:read']],
    denormalizationContext: ['groups' => ['profile:post','user:registration-post']],
    paginationClientItemsPerPage: true,
    paginationMaximumItemsPerPage: 200,
    processor: ProfileProcessor::class
)]
#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'profile')]
#[ORM\Index(columns: ["first_name"], name: "first_name_idx")]
#[ORM\Index(columns: ["last_name"], name: "last_name_idx")]
#[UniqueEntity('slug')]
#[UniqueEntity(
    fields: ['userAlumni', 'clientUuid'],
    message: 'This user has already profile on that client.',
    errorPath: 'userAlumni',
)]
#[ApiFilter(filterClass: IdentifierFilter::class, properties: ['userAlumni.uuid' => 'exact'])]
#[ApiFilter(filterClass: SearchFilter::class, properties: ['userAlumni.email' => 'exact', 'clientUuid' => 'exact', 'clientLegacyId' => 'exact'])]
#[ApiFilter(filterClass: FullTextSearchFilter::class, properties: ['firstName' => 'partial', 'lastName' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['firstName', 'lastName'], arguments: ['orderParameterName' => 'order'])]
#[Groups(['cqrs_export_profile'])]
#[Gedmo\SoftDeleteable()]
class Profile
{
    use SoftDeleteableEntity;
    use TimestampableEntity;
    use UpdatedBy;

    #[ApiProperty(identifier: true)]
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['profile:read','profile:basic-read','cqrs_export_profile_basic'])]
    private ?Uuid $uuid = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['profile:read','profile:post','cqrs_export_profile_basic'])]
    private ?int $clientLegacyId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    #[Assert\NotBlank(message: 'form.validation.enter_client_uuid')]
    #[Groups(['profile:read','profile:post','user:registration-post','cqrs_export_profile_basic','mv:serialize'])]
    private ?string $clientUuid = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(allowNull: true, message: 'form.validation.enter_first_name')]
    #[Groups(['profile:read','profile:post','user:registration-post','profile:basic-read','mv:serialize'])]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(allowNull: true, message: 'form.validation.enter_last_name')]
    #[Groups(['profile:read','profile:post','user:registration-post','profile:basic-read','mv:serialize'])]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['profile:read','profile:post','mv:serialize'])]
    private ?string $nickname = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['profile:read','profile:post','mv:serialize'])]
    private ?string $middleName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['profile:read','profile:post'])]
    private ?string $maidenName = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['profile:read','profile:post'])]
    private ?string $nameTitle = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['profile:read','profile:post'])]
    private ?string $nameSuffix = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['profile:read','profile:post'])]
    private ?string $about = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['profile:read','profile:admin-read','profile:admin-post'])]
    private ?string $adminNotes = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['profile:read','profile:post'])]
    private ?string $externalId = null;

    /**
     * @Gedmo\Slug(fields={"firstName","middleName","lastName"}, updatable=true)
     */
    #[Assert\Regex(pattern: '/[a-z0-9_]+/', message: 'Slug must be at least one character long and can contain lowercase letters, digit or _')]
    #[Assert\NotBlank(groups: ['profile:post'])]
    #[Assert\Length(min: 1, max: 255)]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['profile:read','profile:post','profile:basic-read'])]
    private ?string $slug = null;

    #[ORM\ManyToOne(targetEntity: UserAlumni::class, cascade: ['persist'], inversedBy: 'profiles')]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['profile:read','profile:post','cqrs_export_profile_basic'])]
    private ?UserAlumni $userAlumni = null;

    public function __construct()
    {
        // Any default initialization
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getClientLegacyId(): ?int
    {
        return $this->clientLegacyId;
    }

    public function setClientLegacyId(?int $clientLegacyId): self
    {
        $this->clientLegacyId = $clientLegacyId;
        return $this;
    }

    public function getClientUuid(): ?string
    {
        return $this->clientUuid;
    }

    public function setClientUuid(?string $clientUuid): self
    {
        $this->clientUuid = $clientUuid;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;
        return $this;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): self
    {
        $this->middleName = $middleName;
        return $this;
    }

    public function getMaidenName(): ?string
    {
        return $this->maidenName;
    }

    public function setMaidenName(?string $maidenName): self
    {
        $this->maidenName = $maidenName;
        return $this;
    }

    public function getNameTitle(): ?string
    {
        return $this->nameTitle;
    }

    public function setNameTitle(?string $nameTitle): self
    {
        $this->nameTitle = $nameTitle;
        return $this;
    }

    public function getNameSuffix(): ?string
    {
        return $this->nameSuffix;
    }

    public function setNameSuffix(?string $nameSuffix): self
    {
        $this->nameSuffix = $nameSuffix;
        return $this;
    }

    public function getAbout(): ?string
    {
        return $this->about;
    }

    public function setAbout(?string $about): self
    {
        $this->about = $about;
        return $this;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function setAdminNotes(?string $adminNotes): self
    {
        $this->adminNotes = $adminNotes;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getUserAlumni(): ?UserAlumni
    {
        return $this->userAlumni;
    }

    public function setUserAlumni(?UserAlumni $userAlumni): self
    {
        $this->userAlumni = $userAlumni;
        return $this;
    }
}
