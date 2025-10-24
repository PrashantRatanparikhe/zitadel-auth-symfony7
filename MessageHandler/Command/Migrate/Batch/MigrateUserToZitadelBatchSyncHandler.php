<?php

namespace App\MessageHandler\Command\Migrate\Batch;

use App\Entity\Service\Profile;
use App\Entity\Service\UserAlumni;
use App\Repository\Service\UserAlumniRepository;
use App\Repository\Service\ProfileRepository;
use App\Message\Command\Migrate\Batch\MigrateUserToZitadelBatchSync;
use App\Service\ZitadelService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class MigrateUserToZitadelBatchSyncHandler
 * Handles the migration of user data to Zitadel.
 */
#[AsMessageHandler]
class MigrateUserToZitadelBatchSyncHandler
{
    private string $version;
    private string $syncError;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly UserAlumniRepository $userAlumniRepository,
        private readonly ProfileRepository $profileRepository,
        private readonly ZitadelService $zitadelService
    ) {
        $this->version = $_ENV['ZITADEL_VERSION'];
        $this->syncError = '';
    }

    /**
     * Invokes the migration process for a single user without Zitadel ID.
     *
     * @param MigrateUserToZitadelBatchSync $message The batch sync message.
     * @return void
     */
    public function __invoke(MigrateUserToZitadelBatchSync $message): void
    {
        $userAlumni = $this->userAlumniRepository->findOneBy(['zitadelUserId' => null]);
        $profile = $userAlumni ? $this->profileRepository->findOneBy(['userAlumni' => $userAlumni]) : null;

        if ($userAlumni) {
            try {
                $this->processMigration($userAlumni, $profile, $message);
            } catch (\Throwable $th) {
                $this->syncError = $th->getMessage();
                $this->handleFailure($userAlumni, $message);
            }
        }
    }

    /**
     * Processes the migration of a user to Zitadel.
     *
     * Handles validation, prepares the data, sends it to Zitadel, and updates local records.
     *
     * @param UserAlumni $userAlumni The user to migrate.
     * @param Profile|null $profile The associated profile.
     * @param MigrateUserToZitadelBatchSync $message The batch sync message.
     * @return void
     */
    private function processMigration(UserAlumni $userAlumni, ?Profile $profile, MigrateUserToZitadelBatchSync $message): void
    {
        if (!$profile) {
            $this->syncError = 'Profile not found';
            $this->handleFailure($userAlumni, $message);
            return;
        }

        if (!$this->validateUserData($userAlumni, $profile)) {
            $this->handleFailure($userAlumni, $message);
            return;
        }

        $url = '/management/' . $this->version . '/users/human/_import';
        $data = $this->zitadelService->prepareData($userAlumni, $profile);
        $response = $this->zitadelService->post($url, $data);

        if (empty($response['userId'])) {
            $this->syncError = $response['message'] ?? 'Something went wrong';
            $this->handleFailure($userAlumni, $message);
            return;
        }

        /** Update local user record with Zitadel ID */
        $userAlumni->setZitadelUserId($response['userId']);
        $this->userAlumniRepository->save($userAlumni, true);

        /** Dispatch next user migration in batch */
        $this->dispatchNext($message);
    }

    /**
     * Validates the user data before migration.
     *
     * Checks that the user has email, first name, and last name.
     *
     * @param UserAlumni $userAlumni The user to validate.
     * @param Profile $profile The associated profile.
     * @return bool True if validation passes, false otherwise.
     */
    private function validateUserData(UserAlumni $userAlumni, Profile $profile): bool
    {
        if (empty($userAlumni->getEmail()) || empty($profile->getFirstName()) || empty($profile->getLastName())) {
            $this->syncError = 'First name, last name, and email are required';
            return false;
        }
        return true;
    }

    /**
     * Handles the failure of the migration process.
     *
     * Updates the local user record with sync error and dispatches next batch migration.
     *
     * @param UserAlumni $userAlumni The user that failed migration.
     * @param MigrateUserToZitadelBatchSync $message The batch sync message.
     * @return void
     */
    private function handleFailure(UserAlumni $userAlumni, MigrateUserToZitadelBatchSync $message): void
    {
        $userAlumni->setZitadelUserId(0);
        if (!empty($this->syncError)) {
            $userAlumni->setZitadelSyncError($this->syncError);
        }
        $this->userAlumniRepository->save($userAlumni, true);

        /** Continue with the next user in the batch */
        $this->dispatchNext($message);
    }

    /**
     * Dispatches the next migration message to process the next user in the batch.
     *
     * @param MigrateUserToZitadelBatchSync $message The current batch message.
     * @return void
     */
    private function dispatchNext(MigrateUserToZitadelBatchSync $message): void
    {
        $this->commandBus->dispatch(new MigrateUserToZitadelBatchSync());
    }
}
