<?php

namespace App\EventListener;

use App\Entity\Service\{Address, ContactEmail, PhoneNumber, SocialMediaLink, UserAlumni, Profile, Admin\ClientUserSection, ProfileSectionField, UserInfo, UserMedia};
use App\Message\Command\CQRS\MSZitadel\SyncUserToZitadelMessage;
use App\Repository\Service\ProfileRepository;
use App\Service\{ZitadelService, MaterializedViewService};
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Subscriber for handling entity lifecycle events related to users and their profiles.
 */
class UserListener implements EventSubscriber
{
    private string $version;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRepository $profileRepository,
        private readonly ZitadelService $zitadelService,
        private readonly MaterializedViewService $materializedView
    ) {
        $this->version = $_ENV['ZITADEL_VERSION'];
    }

    /**
     * Returns the Doctrine events this subscriber listens to.
     *
     * @return array
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::preRemove,
        ];
    }

    /**
     * Handle postPersist lifecycle event.
     *
     * @param LifecycleEventArgs $args
     * @return void
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        try {
            /** If new user alumni created, handle Zitadel sync */
            if ($object instanceof UserAlumni) {
                $this->handleNewUserAlumni($object);
            }
        } catch (\Throwable $th) {
            $this->logger->error('Unable to create user in Zitadel: ' . $th->getMessage());
        }

        /** Log the method call */
        $this->logger->info(sprintf('UserListener: %s', __METHOD__));

        /** Sync materialized views for search and reporting */
        $this->syncMaterializedViews($object, 'save');
    }

    /**
     * Handle postUpdate lifecycle event.
     *
     * @param LifecycleEventArgs $args
     * @return void
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        try {
            /** Update Zitadel profile if Profile entity changed */
            if ($object instanceof Profile) {
                $this->putProfile($object);
                $this->dispatchUserSyncIfMissing($object->getUserAlumni());
            }

            /** Update Zitadel user email/username if UserAlumni changed */
            if ($object instanceof UserAlumni) {
                $this->putUserAlumni($object);
            }
        } catch (\Throwable $th) {
            $this->logger->error('Unable to sync user to Zitadel: ' . $th->getMessage());
        }

        try {
            /** Sync materialized views for updated entity */
            $this->syncMaterializedViews($object, 'save');
        } catch (\Throwable $th) {
            $this->logger->error('Materialized view sync error: ' . $th->getMessage());
        }
    }

    /**
     * Handle preRemove lifecycle event.
     *
     * @param LifecycleEventArgs $args
     * @return void
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        try {
            /** Sync materialized views on delete */
            $this->syncMaterializedViews($object, 'delete');
        } catch (\Throwable $th) {
            $this->logger->error('Error syncing on remove: ' . $th->getMessage());
        }
    }

    /**
     * Update Zitadel profile for given profile entity.
     *
     * @param Profile $object
     * @return void
     */
    private function putProfile(Profile $object): void
    {
        /** Get changed fields from UnitOfWork */
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($object);

        /** Only proceed if there are changes and Zitadel ID exists */
        if (!empty($changeSet) && $object->getUserAlumni()?->getZitadelUserId()) {
            $fields = array_keys($changeSet);

            /** Sync if relevant profile fields have changed */
            if (array_intersect($fields, ['firstName', 'lastName', 'nickname'])) {
                $profileData = [
                    'firstName' => $object->getFirstName(),
                    'lastName' => $object->getLastName(),
                    'displayName' => $object->getFullName(),
                    'nickName' => $object->getNickname(),
                ];

                /** Prepare URL for Zitadel API */
                $url = "/management/{$this->version}/users/{$object->getUserAlumni()->getZitadelUserId()}/profile";

                /** Dispatch async message to update profile in Zitadel */
                $this->messageBus->dispatch(new SyncUserToZitadelMessage($url, json_encode($profileData), 'update'));
            }
        }
    }

    /**
     * Update Zitadel user email and username.
     *
     * @param UserAlumni $object
     * @return void
     */
    private function putUserAlumni(UserAlumni $object): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($object);

        if (!empty($changeSet) && $object->getZitadelUserId()) {
            /** If email changed, sync email and username */
            if (array_key_exists('email', $changeSet)) {
                $emailData = [
                    'email' => $object->getEmail(),
                    'isEmailVerified' => (bool) $object->getEmailConfirmation(),
                ];
                $emailUrl = "/management/{$this->version}/users/{$object->getZitadelUserId()}/email";
                $this->messageBus->dispatch(new SyncUserToZitadelMessage($emailUrl, json_encode($emailData), 'update'));

                $usernameData = [
                    'userName' => $object->getEmail(),
                ];
                $usernameUrl = "/management/{$this->version}/users/{$object->getZitadelUserId()}/username";
                $this->messageBus->dispatch(new SyncUserToZitadelMessage($usernameUrl, json_encode($usernameData), 'update'));
            }
        }
    }

    /**
     * Search Zitadel user by email.
     *
     * @param string $email
     * @return array|null
     */
    private function checkIfUserExistOnZitadel(string $email): ?array
    {
        $this->logger->info(sprintf('Checking user existence at Zitadel: %s', $email));

        /** Prepare search query for Zitadel API */
        $url = "/management/{$this->version}/users/_search";
        $searchQuery = [
            'queries' => [[
                'userNameQuery' => [
                    'userName' => $email,
                    'method' => 'TEXT_QUERY_METHOD_EQUALS',
                ],
            ]],
        ];

        /** Call Zitadel API to search user */
        return $this->zitadelService->post($url, $searchQuery);
    }

    /**
     * Dispatch message to sync new user to Zitadel.
     *
     * @param UserAlumni $userAlumni
     * @param Profile $profile
     * @return void
     */
    private function dispatchSyncNewUser(UserAlumni $userAlumni, Profile $profile): void
    {
        $this->logger->info(sprintf('Dispatching new user to Zitadel: %s', $userAlumni->getEmail()));

        $url = "/management/{$this->version}/users/human/_import";
        $data = $this->zitadelService->prepareData($userAlumni, $profile);

        /** Dispatch asynchronous import message to Zitadel */
        $this->messageBus->dispatch(new SyncUserToZitadelMessage($url, json_encode($data), 'create', $userAlumni->getUuid()->toRfc4122()));
    }

    /**
     * Handle new user alumni creation logic.
     *
     * @param UserAlumni $user
     * @return void
     */
    private function handleNewUserAlumni(UserAlumni $user): void
    {
        /** Find the profile associated with this user */
        $profile = $this->profileRepository->findOneBy(['userAlumni' => $user]);

        /** If profile exists but Zitadel ID missing, check or create in Zitadel */
        if ($profile && !$user->getZitadelUserId()) {
            $response = $this->checkIfUserExistOnZitadel($user->getEmail());
            if (!empty($response['result'][0]['id'])) {
                $user->setZitadelUserId($response['result'][0]['id']);
                $this->entityManager->flush();
            } else {
                $this->dispatchSyncNewUser($user, $profile);
            }
        }
    }

    /**
     * If Zitadel ID is missing, try to create new user at Zitadel.
     *
     * @param UserAlumni $user
     * @return void
     */
    private function dispatchUserSyncIfMissing(UserAlumni $user): void
    {
        if ($user->getEmail() && !$user->getZitadelUserId()) {
            $response = $this->checkIfUserExistOnZitadel($user->getEmail());
            if (empty($response['result'][0]['id'])) {
                $profile = $this->profileRepository->findOneBy(['userAlumni' => $user]);
                if ($profile) {
                    $this->dispatchSyncNewUser($user, $profile);
                }
            }
        }
    }
}
