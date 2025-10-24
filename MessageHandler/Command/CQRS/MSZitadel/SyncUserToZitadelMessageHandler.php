<?php

namespace App\MessageHandler\Command\CQRS\MSZitadel;

use App\Message\Command\CQRS\MSZitadel\SyncUserToZitadelMessage;
use App\Repository\Service\UserAlumniRepository;
use App\Repository\Service\ProfileRepository;
use App\Service\ZitadelService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncUserToZitadelMessageHandler
{
    /** Constants for supported actions */
    final public const ACTION_CREATE = 'create';
    final public const ACTION_UPDATE = 'update';

    public function __construct(
        private readonly UserAlumniRepository $userAlumniRepository,
        private readonly ZitadelService $zitadelService,
        private readonly ProfileRepository $profileRepository
    ) {}

    /**
     * Handles the incoming SyncUserToZitadelMessage.
     *
     * Determines the action type (create/update) and delegates to the corresponding method.
     *
     * @param SyncUserToZitadelMessage $message
     * @return void
     */
    public function __invoke(SyncUserToZitadelMessage $message): void
    {
        $action = $message->getAction();
        $url = $message->getUrl();

        /** Decode JSON payload from the message */
        $data = json_decode($message->getData(), true, 512, JSON_THROW_ON_ERROR);
        $userUuid = $message->getUserUuid();

        /** Dispatch based on action type */
        match ($action) {
            self::ACTION_CREATE => $this->handleCreateAction($url, $data, $userUuid),
            self::ACTION_UPDATE => $this->handleUpdateAction($url, $data),
            default => throw new \InvalidArgumentException(sprintf('Invalid action provided: "%s"', $action)),
        };
    }

    /**
     * Handles the creation of a new user in Zitadel.
     *
     * Sends a POST request to the Zitadel API and updates the local UserAlumni record
     * with the Zitadel user ID if the creation succeeds.
     *
     * @param string $url The URL for the Zitadel API.
     * @param array $data The data to be sent to Zitadel.
     * @param string|null $userUuid The UUID of the user in local database.
     * @return void
     */
    private function handleCreateAction(string $url, array $data, ?string $userUuid): void
    {
        /** Send create request to Zitadel */
        $response = $this->zitadelService->post($url, $data);

        /** If Zitadel returned a userId, update local record */
        if (!empty($response['userId']) && !empty($userUuid)) {
            $this->updateUserAlumniZitadelId($userUuid, $response['userId']);
        }
    }

    /**
     * Updates the user alumni record with the Zitadel user ID.
     *
     * @param string $userUuid The UUID of the user in local database.
     * @param string $zitadelUserId The user ID returned from Zitadel.
     * @return void
     */
    private function updateUserAlumniZitadelId(string $userUuid, string $zitadelUserId): void
    {
        /** Find the UserAlumni entity by UUID */
        if ($userAlumni = $this->userAlumniRepository->findUserByUuid($userUuid)) {
            /** Set the Zitadel user ID and persist */
            $userAlumni->setZitadelUserId($zitadelUserId);
            $this->userAlumniRepository->save($userAlumni, true);
        }
    }

    /**
     * Handles the update of an existing user in Zitadel.
     *
     * Sends a PUT request to the Zitadel API with the updated user data.
     *
     * @param string $url The URL for the Zitadel API.
     * @param array $data The data to be sent to Zitadel.
     * @return void
     */
    private function handleUpdateAction(string $url, array $data): void
    {
        /** Send update request to Zitadel */
        $this->zitadelService->put($url, $data);
    }
}
