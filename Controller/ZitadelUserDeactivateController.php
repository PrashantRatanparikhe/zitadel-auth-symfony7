<?php

namespace App\Controller;

use App\Entity\Service\UserAlumni;
use App\Repository\Service\UserAlumniRepository;
use App\Repository\Service\ClientDomainRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\Command\UserAlumni\UserDeactivateMessage;

class ZitadelUserDeactivateController extends AbstractController
{
    private ManagerRegistry $doctrine;
    private UserAlumniRepository $userAlumniRepository;
    private ClientDomainRepository $clientDomainRepository;
    private MessageBusInterface $messageBus;
    private $em;
    private array $data = [];
    private ?string $clientUuid = null;
    private $clientDomain;

    public function __construct(
        ManagerRegistry $doctrine,
        UserAlumniRepository $userAlumniRepository,
        ClientDomainRepository $clientDomainRepository,
        MessageBusInterface $messageBus
    ) {
        $this->doctrine = $doctrine;
        $this->userAlumniRepository = $userAlumniRepository;
        $this->clientDomainRepository = $clientDomainRepository;
        $this->messageBus = $messageBus;
        $this->em = $doctrine->getManager('service');
    }

    #[Route(path: '/api/zitadel_user_deactivate', methods: ['POST'], name: 'zitadel_user_deactivate')]
    public function deactivateUser(Request $request): Response
    {
        try {
            /** Decode request JSON body */
            $this->data = json_decode($request->getContent(), true);

            /** Validate request */
            $this->validateRequest();

            /** Get client UUID by callback URL */
            $this->clientUuid = $this->getClientUuidByCallbackUrl();

            /** Find user by Zitadel User ID */
            $userAlumni = $this->getUserAlumni();

            /** Deactivate the user */
            $userAlumni->setEnabled(false);

            /** Persist the deactivation */
            $this->em->flush();

            /** Dispatch deactivate message via CQRS */
            $json = json_encode([
                'userUuid' => $userAlumni->getUuid()->toRfc4122(),
                'clientUuid' => $this->clientUuid,
            ], JSON_THROW_ON_ERROR);

            $this->messageBus->dispatch(new UserDeactivateMessage($json));

            /** Return success response */
            return $this->json(['success' => true]);
        } catch (\Throwable $th) {
            /** Return failure response */
            return $this->json(['success' => false]);
        }
    }

    /**
     * Validates required fields for deactivation.
     * @throws UnprocessableEntityHttpException
     */
    private function validateRequest(): void
    {
        /** Check if Zitadel User ID exists in request */
        if (empty($this->data) || empty($this->data['zitadelUserId'])) {
            throw new UnprocessableEntityHttpException('Data is not valid');
        }
    }

    /**
     * Get UserAlumni entity by Zitadel User ID.
     * @return UserAlumni
     */
    private function getUserAlumni(): UserAlumni
    {
        /** Find the user by Zitadel ID */
        $userAlumni = $this->userAlumniRepository->findOneBy(['zitadelUserId' => $this->data['zitadelUserId']]);

        /** Throw exception if user not found */
        if (!$userAlumni) {
            throw new UnprocessableEntityHttpException('User not found.');
        }

        /** Return the user entity */
        return $userAlumni;
    }

    /**
     * Get client UUID by callback URL.
     * @return string|null
     */
    private function getClientUuidByCallbackUrl(): ?string
    {
        try {
            /** Parse callback URL */
            $parsedUrl = parse_url($this->data['callbackUrl']);
            $url = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            /** Find client domain entity */
            $this->clientDomain = $this->clientDomainRepository->findOneBy(['url' => [$parsedUrl['host'], $url]]);

            /** Return client UUID if found */
            return $this->clientDomain ? $this->clientDomain->getClient()->getUuid() : null;
        } catch (\Throwable $th) {
            return null;
        }
    }
}
