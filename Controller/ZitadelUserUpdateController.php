<?php

namespace App\Controller;

use App\Entity\Service\UserAlumni;
use App\Entity\Service\Profile;
use App\Repository\Service\UserAlumniRepository;
use App\Repository\Service\ProfileRepository;
use App\Message\Command\UserAlumni\UserUpdateMessage;
use App\Repository\Service\ClientDomainRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Messenger\MessageBusInterface;

class ZitadelUserUpdateController extends AbstractController
{
    private ManagerRegistry $doctrine;
    private UserAlumniRepository $userAlumniRepository;
    private ProfileRepository $profileRepository;
    private ClientDomainRepository $clientDomainRepository;
    private MessageBusInterface $messageBus;
    private $em;
    private array $data = [];
    private ?string $clientUuid = null;
    private $clientDomain;

    public function __construct(
        ManagerRegistry $doctrine,
        UserAlumniRepository $userAlumniRepository,
        ProfileRepository $profileRepository,
        ClientDomainRepository $clientDomainRepository,
        MessageBusInterface $messageBus
    ) {
        $this->doctrine = $doctrine;
        $this->userAlumniRepository = $userAlumniRepository;
        $this->profileRepository = $profileRepository;
        $this->clientDomainRepository = $clientDomainRepository;
        $this->messageBus = $messageBus;
        $this->em = $doctrine->getManager('service');
    }

    #[Route(path: '/api/zitadel_user_update', methods: ['POST'], name: 'zitadel_user_update')]
    public function updateUser(Request $request): Response
    {
        try {
            /** Decode JSON request body into array */
            $this->data = json_decode($request->getContent(), true);

            /** Validate required fields */
            $this->validateRequest();

            /** Get client UUID */
            $this->clientUuid = $this->getClientUuidByCallbackUrl();

            /** Find UserAlumni */
            $userAlumni = $this->getUserAlumni();

            /** Find Profile */
            $profile = $this->profileRepository->findOneBy([
                'userAlumni' => $userAlumni,
                'clientUuid' => $this->clientUuid,
            ]);

            /** Throw exception if profile not found */
            if (!$profile) {
                throw new UnprocessableEntityHttpException('Profile not found.');
            }

            /** Update user info */
            if (!empty($this->data['firstName'])) {
                $profile->setFirstName($this->data['firstName']);
            }
            if (!empty($this->data['lastName'])) {
                $profile->setLastName($this->data['lastName']);
            }

            /** Persist changes */
            $this->em->flush();

            /** Dispatch update message via CQRS */
            $json = json_encode([
                'userUuid' => $userAlumni->getUuid()->toRfc4122(),
                'profileUuid' => $profile->getUuid()->toRfc4122(),
                'clientUuid' => $this->clientUuid,
            ], JSON_THROW_ON_ERROR);

            $this->messageBus->dispatch(new UserUpdateMessage($json));

            /** Return success response */
            return $this->json(['success' => true]);
        } catch (\Throwable $th) {
            /** Return failure response on error */
            return $this->json(['success' => false]);
        }
    }

    /**
     * Validates required fields for user update.
     * @throws UnprocessableEntityHttpException
     */
    private function validateRequest(): void
    {
        if (empty($this->data) || empty($this->data['zitadelUserId'])) {
            throw new UnprocessableEntityHttpException('Data is not valid');
        }
    }

    /**
     * Retrieves UserAlumni by Zitadel User ID.
     * @return UserAlumni
     */
    private function getUserAlumni(): UserAlumni
    {
        $userAlumni = $this->userAlumniRepository->findOneBy(['zitadelUserId' => $this->data['zitadelUserId']]);
        if (!$userAlumni) {
            throw new UnprocessableEntityHttpException('User not found.');
        }
        return $userAlumni;
    }

    /**
     * Get client UUID by callback URL.
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
}
