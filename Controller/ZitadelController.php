<?php

namespace App\Controller;

use App\Entity\Service\UserAlumni;
use App\Entity\Service\Profile;
use App\Entity\Service\EmailConfirmation;
use App\Repository\Service\UserAlumniRepository;
use App\Repository\Service\ProfileRepository;
use App\Message\Command\UserAlumni\RegisterMessage;
use App\Repository\Service\ClientDomainRepository;
use App\Service\TokenGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ZitadelController extends AbstractController
{
    private TokenGenerator $tokenGenerator;
    private ManagerRegistry $doctrine;
    private UserPasswordHasherInterface $passwordHasher;
    private ClientDomainRepository $clientDomainRepository;
    private MessageBusInterface $messageBus;
    private UserAlumniRepository $userAlumniRepository;
    private ProfileRepository $profileRepository;

    private ?string $token = null;
    private array $data = [];
    private $em;
    private ?EmailConfirmation $emailConfirmation = null;
    private ?UserAlumni $userAlumni = null;
    private ?Profile $profile = null;
    private ?string $clientUuid = null;
    private $clientDomain;

    public function __construct(
        TokenGenerator $tokenGenerator,
        ManagerRegistry $doctrine,
        UserPasswordHasherInterface $passwordHasher,
        ClientDomainRepository $clientDomainRepository,
        MessageBusInterface $messageBus,
        UserAlumniRepository $userAlumniRepository,
        ProfileRepository $profileRepository
    ) {
        $this->tokenGenerator = $tokenGenerator;
        $this->doctrine = $doctrine;
        $this->passwordHasher = $passwordHasher;
        $this->clientDomainRepository = $clientDomainRepository;
        $this->messageBus = $messageBus;
        $this->userAlumniRepository = $userAlumniRepository;
        $this->profileRepository = $profileRepository;
        $this->em = $doctrine->getManager('service');
    }

    #[Route(path: '/api/zitadel_user', methods: ['POST'], name: 'zitadel_user')]
    public function postHashes(Request $request): Response
    {
        try {
            $this->data = json_decode($request->getContent(), true);

            /** Validation */
            $this->validateRequest();

            /** Get Client Uuid */
            $this->clientUuid = $this->getClientUuidByCallbackUrl();

            /** Generate token */
            $this->token = $this->tokenGenerator->getRandomToken(10);

            /** User Registration */
            $this->registerUser();

            /** Return success response */
            return $this->json(['success' => true]);
        } catch (\Throwable $th) {
            /** On any error, return failure response */
            return $this->json(['success' => false]);
        }
    }

    /**
     * Validates the incoming request data.
     *
     * @throws UnprocessableEntityHttpException
     * @return void
     */
    private function validateRequest(): void
    {
        /** 
         * Check if all required data fields exist and the referer is valid
         * Throws UnprocessableEntityHttpException if validation fails
         */
        if (
            empty($this->data) ||
            empty($this->data['email']) ||
            empty($this->data['firstName']) ||
            empty($this->data['lastName']) ||
            empty($this->data['callbackUrl']) ||
            empty($this->data['referer']) ||
            !$this->isValidReferer()
        ) {
            throw new UnprocessableEntityHttpException('Data is not valid');
        }
    }

    /**
     * Registers a user based on the provided data.
     * @return void
     */
    private function registerUser(): void
    {
        /** Check if a user with the given email already exists */
        $isUserExist = $this->userAlumniRepository->findOneBy(['email' => $this->data['email']]);

        /** Check if a profile exists for this user under the current client */
        $isProfileExist = $isUserExist ? $this->profileRepository->findOneBy(['userAlumni' => $isUserExist, 'clientUuid' => $this->clientUuid]) : null;

        /** If both user and profile exist, update the Zitadel user ID */
        if ($isUserExist && $isProfileExist) {
            $isUserExist->setZitadelUserId($this->data['zitadelUserId']);
            /** Persist changes to the database */
            $this->em->flush();
        } else {
            /** Create a new user and associated entities */
            $this->createNewUser();
        }
    }

    /**
     * Creates a new user and associated entities.
     * @return void
     */
    private function createNewUser(): void
    {
        /** Create email confirmation entity for the new user */
        $this->createEmailConfirmation();

        /** Create the user alumni entity */
        $this->createUserAlumni();

        /** Create the profile entity for the user */
        $this->createProfile();
    }

    /**
     * Creates a email confirmation.
     * @return void
     */
    private function createEmailConfirmation(): void
    {
        /** Initialize a new EmailConfirmation entity */
        $this->emailConfirmation = new EmailConfirmation();
        $this->emailConfirmation->setEmail($this->data['email']);
        $this->emailConfirmation->setToken($this->token);
        $this->emailConfirmation->setConfirmToken($this->token);
        $this->emailConfirmation->setConfirmedAt(new \DateTimeImmutable());
        $this->emailConfirmation->setCreatedAt(new \DateTimeImmutable());

        /** Persist and flush the email confirmation entity */
        $this->em->persist($this->emailConfirmation);
        $this->em->flush();
    }

    /**
     * Creates a user alumni.
     * @return void
     */
    private function createUserAlumni(): void
    {
        /** Initialize a new UserAlumni entity */
        $this->userAlumni = new UserAlumni();

        /** Set the email address for the user */
        $this->userAlumni->setEmail($this->data['email']);

        /** Hash and set the password if provided */
        if (!empty($this->data['password'])) {
            $hashedPassword = $this->passwordHasher->hashPassword($this->userAlumni, $this->data['password']);
            $this->userAlumni->setPassword($hashedPassword);
        }

        /** Set user flags and link email confirmation */
        $this->userAlumni->setEnabled(true)
            ->setConfirmed(true)
            ->setTermsAndConditions(true)
            ->setTermsAcceptedAt(new \DateTime())
            ->setEmailConfirmation($this->emailConfirmation)
            ->setZitadelUserId($this->data['zitadelUserId']);

        /** Persist the user entity */
        $this->em->persist($this->userAlumni);

        /** Link the email confirmation to the user */
        $this->emailConfirmation->setUserAlumni($this->userAlumni);

        /** Flush changes to the database */
        $this->em->flush();
    }

    /**
     * Creates a profile.
     * @return void
     */
    private function createProfile(): void
    {
        /** Initialize a new Profile entity */
        $this->profile = new Profile();

        /** Set the user, client UUID, name, roles, and creation date */
        $this->profile->setUserAlumni($this->userAlumni)
            ->setClientUuid($this->clientUuid)
            ->setFirstName($this->data['firstName'])
            ->setLastName($this->data['lastName'])
            ->setRoles(['ROLE_USER'])
            ->setCreatedAt(new \DateTime());

        /** Persist the profile entity */
        $this->em->persist($this->profile);

        /** Flush changes to the database */
        $this->em->flush();

        /** Dispatch registration message for asynchronous processing */
        $this->dispatchRegisterMessage();
    }

    /**
     * Dispatch a register message.
     * @return void
     */
    private function dispatchRegisterMessage(): void
    {
        /** Prepare JSON payload with user, profile, client, and domain UUIDs */
        $json = json_encode([
            'userUuid'    => $this->userAlumni->getUuid()->toRfc4122(),
            'profileUuid' => $this->profile->getUuid()->toRfc4122(),
            'clientUuid'  => $this->clientUuid,
            'domainUuid'  => $this->clientDomain->getUuid()->toRfc4122()
        ], JSON_THROW_ON_ERROR);

        /** Dispatch the registration message asynchronously via Symfony Messenger */
        $this->messageBus->dispatch(new RegisterMessage($json));
    }

    /**
     * Get client uuid by callback url.
     * @return string|null
     */
    private function getClientUuidByCallbackUrl(): ?string
    {
        try {
            $parsedUrl = parse_url($this->data['callbackUrl']);
            $url = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            $this->clientDomain = $this->clientDomainRepository->findOneBy(['url' => [$parsedUrl['host'], $url]]);
            return $this->clientDomain ? $this->clientDomain->getClient()->getUuid() : null;
        } catch (\Throwable $th) {
            return null;
        }
    }

    /**
     * Check if the referer is valid or not.
     * @return bool
     */
    private function isValidReferer(): bool
    {
        try {
            /** Parse the referer URL to extract scheme and host */
            $parsedUrl = parse_url($this->data['referer']);

            /** Construct the base URL from the referer */
            $referer = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            /** Compare the referer with the allowed base URL from environment */
            return $referer === $_ENV['ZITADEL_BASE_URL'];
        } catch (\Throwable $th) {
            /** Return false if any error occurs during parsing */
            return false;
        }
    }

    #[Route(path: '/api/zitadel_user_last_login', methods: ['POST'], name: 'zitadel_user_last_login')]
    public function postAuthentication(Request $request): Response
    {
        try {
            /** Decode JSON request body into an array */
            $this->data = json_decode($request->getContent(), true);

            /** Validate required fields and referer for post authentication */
            $this->validateRequestForPostAuthentication();

            /** Get Client UUID based on callback URL */
            $this->clientUuid = $this->getClientUuidByCallbackUrl();

            /** Retrieve the user based on Zitadel User ID or username */
            $userAlumni = $this->getUserAlumni();

            /** Validate the profile exists for this client UUID */
            $profile = $this->profileRepository->findOneBy([
                'userAlumni' => $userAlumni,
                'clientUuid' => $this->clientUuid,
            ]);

            /** Throw exception if profile is not found */
            if (!$profile) {
                throw new UnprocessableEntityHttpException('User profile not found.');
            }

            /** Update the user's last login timestamp */
            $userAlumni->setLastLoginAt(new \DateTime($this->data['loginTimestamp']));

            /** Persist changes to the database */
            $this->em->flush();

            /** Return success response */
            return $this->json(['success' => true]);
        } catch (\Throwable $th) {
            /** Return failure response on error */
            return $this->json(['success' => false]);
        }
    }

    /**
     * Validates the incoming request data for post authentication.
     * @throws UnprocessableEntityHttpException
     */
    private function validateRequestForPostAuthentication(): void
    {
        /** Check if request data or Zitadel User ID is missing, or referer is invalid */
        if (empty($this->data) || empty($this->data['zitadelUserId']) || !$this->isValidReferer()) {
            /** Throw exception if validation fails */
            throw new UnprocessableEntityHttpException('Data is not valid');
        }
    }

    /**
     * Retrieves the UserAlumni entity based on Zitadel User Id or email.
     * @return UserAlumni|null
     */
    private function getUserAlumni(): ?UserAlumni
    {
        /** Try to find the user by Zitadel User ID */
        $userAlumni = $this->userAlumniRepository->findOneBy(['zitadelUserId' => $this->data['zitadelUserId']]);

        /** If not found, and username is provided, try to find by email */
        if (!$userAlumni && !empty($this->data['userName'])) {
            $userAlumni = $this->userAlumniRepository->findOneBy(['email' => $this->data['userName']]);
        }

        /** Throw exception if user still not found */
        if (!$userAlumni) {
            throw new UnprocessableEntityHttpException('User not found.');
        }

        /** Return the found UserAlumni entity */
        return $userAlumni;
    }
}
