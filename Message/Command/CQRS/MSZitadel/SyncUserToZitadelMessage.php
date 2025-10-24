<?php

namespace App\Message\Command\CQRS\MSZitadel;

/**
 * Class SyncUserToZitadelMessage
 * 
 * This class represents a message for synchronizing a user to Zitadel.
 */
final class SyncUserToZitadelMessage
{
    private readonly string $url;
    private readonly string $data;
    private readonly string $action;
    private ?string $userUuid;

    /**
     * SyncUserToZitadelMessage constructor.
     *
     * @param string $url The URL for the synchronization.
     * @param string $data The data to be synchronized.
     * @param string $action The action to be performed.
     * @param string|null $userUuid The UUID of the user (optional).
     */
    public function __construct(string $url, string $data, string $action, ?string $userUuid = null)
    {
        $this->url = $url;
        $this->data = $data;
        $this->action = $action;
        $this->userUuid = $userUuid;
    }

    /**
     * Get the URL.
     *
     * @return string The URL for the synchronization.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the data.
     *
     * @return string The data to be synchronized.
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Get the action.
     *
     * @return string The action to be performed.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the user UUID.
     *
     * @return string|null The UUID of the user or null if not set.
     */
    public function getUserUuid(): ?string
    {
        return $this->userUuid;
    }
}
