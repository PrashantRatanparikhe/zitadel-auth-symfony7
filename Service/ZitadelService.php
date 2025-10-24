<?php

namespace App\Service;

use App\Entity\Service\UserAlumni;
use App\Entity\Service\Profile;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Exception;

class ZitadelService
{
    /** @var CurlHttpClient */
    protected $httpClient;

    private string $clientId;
    private string $clientSecret;
    private string $tokenEndpoint;
    private ?FilesystemAdapter $cache;

    public function __construct()
    {
        // Initialize the HTTP client with the Zitadel base URL
        $this->httpClient = new CurlHttpClient([
            'base_uri' => $_ENV['ZITADEL_BASE_URL'],
        ]);

        // Set the client credentials and token endpoint
        $this->clientId = $_ENV['ZITADEL_CLIENT_ID'];
        $this->clientSecret = $_ENV['ZITADEL_CLIENT_SECRET'];
        $this->tokenEndpoint = $_ENV['ZITADEL_TOKEN_ENDPOINT'];

        // Initialize cache for storing access tokens
        $this->cache = new FilesystemAdapter();
    }

    /**
     * Sends a POST request to the specified URL with the provided parameters.
     *
     * @param string $url The URL to send the request to.
     * @param array $param The parameters to include in the request body.
     * @return array The response data from Zitadel.
     */
    public function post(string $url, array $param): array
    {
        // Reuse the generic sendRequest method for POST requests
        return $this->sendRequest('POST', $url, $param);
    }

    /**
     * Sends a PUT request to the specified URL with the provided parameters.
     *
     * @param string $url The URL to send the request to.
     * @param array $param The parameters to include in the request body.
     * @return array The response data from Zitadel.
     */
    public function put(string $url, array $param): array
    {
        // Reuse the generic sendRequest method for PUT requests
        return $this->sendRequest('PUT', $url, $param);
    }

    /**
     * Sends a GET request to the specified URL.
     *
     * @param string $url The URL to send the request to.
     * @return array The response data from Zitadel.
     */
    public function get(string $url): array
    {
        // Reuse the generic sendRequest method for GET requests
        return $this->sendRequest('GET', $url);
    }

    /**
     * Sends a DELETE request to the specified URL.
     *
     * @param string $url The URL to send the request to.
     * @return array The response data from Zitadel.
     */
    public function delete(string $url): array
    {
        // Reuse the generic sendRequest method for DELETE requests
        return $this->sendRequest('DELETE', $url);
    }

    /**
     * Prepares the headers for JSON requests including the Bearer token.
     *
     * @return array The headers to include in HTTP requests.
     */
    private function getHeader(): array
    {
        // Check cache for access token, or retrieve a new one
        $accessToken = $this->cache->hasItem('token_data')
            ? $this->cache->getItem('token_data')->get()['access_token']
            : $this->getAccessToken();

        // Return headers including Authorization
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken
        ];
    }

    /**
     * Prepares headers for form URL-encoded requests.
     *
     * @return array Headers for form submission.
     */
    private function getHeaderForFormUrlEncode(): array
    {
        // Form URL encoded content type
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }

    /**
     * Retrieves an access token from Zitadel authentication server.
     *
     * @return string|null The access token, or null if failed.
     */
    private function getAccessToken(): ?string
    {
        try {
            // Send POST request to token endpoint
            $response = $this->httpClient->request(
                'POST',
                $this->tokenEndpoint,
                [
                    'headers' => $this->getHeaderForFormUrlEncode(),
                    'body' => [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope' => 'openid urn:zitadel:iam:org:project:id:zitadel:aud',
                        'grant_type' => 'client_credentials'
                    ]
                ]
            );

            // Decode JSON response
            $responseData = json_decode($response->getContent(), true);

            // Cache the token if available
            if (!empty($responseData['access_token'])) {
                $this->setCache($responseData);
                return $responseData['access_token'];
            }

            return null;
        } catch (Exception $exception) {
            // Return null on any exception
            return null;
        }
    }

    /**
     * Prepares user data for sending to Zitadel.
     *
     * @param UserAlumni $userAlumni User entity.
     * @param Profile $profile Profile entity.
     * @return array Prepared data array.
     */
    public function prepareData(UserAlumni $userAlumni, Profile $profile): array
    {
        // Structure data for Zitadel import API
        return [
            'userName' => $userAlumni->getUsername(),
            'profile' => [
                'firstName' => $profile->getFirstName(),
                'lastName' => $profile->getLastName(),
                'displayName' => $profile->getFullName(),
                'nickName' => $profile->getNickname() ?? ''
            ],
            'email' => [
                'email' => $userAlumni->getEmail(),
                'isEmailVerified' => true
            ],
            'hashedPassword' => [
                'value' => $userAlumni->getPassword(),
            ]
        ];
    }

    /**
     * Handles Zitadel request errors.
     *
     * @param \Throwable $e Exception thrown by HTTP client.
     * @return array Error message array.
     */
    private function handleZitadelError(\Throwable $e): array
    {
        try {
            // Try to extract message from response body
            $response = $e->getResponse();
            $content = $response->getContent(false);
            $data = json_decode($content, true);
            return ['message' => $data['message'] ?? $e->getMessage()];
        } catch (\Throwable $th) {
            // Fallback: return exception message
            return ['message' => $e->getMessage()];
        }
    }

    /**
     * Caches access token with expiration.
     *
     * @param array $data Access token and expiry info.
     * @return void
     */
    private function setCache(array $data): void
    {
        // Retrieve cache object and set token data
        $cacheObject = $this->cache->getItem('token_data');
        $cacheObject->set($data);

        // Set token expiration slightly earlier than actual
        $cacheObject->expiresAfter($data['expires_in'] - 10);

        // Save to cache
        $this->cache->save($cacheObject);
    }

    /**
     * Sends an HTTP request to Zitadel API and handles exceptions.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string $url API endpoint.
     * @param array|null $param Optional request body parameters.
     * @return array Response data or error message.
     */
    private function sendRequest(string $method, string $url, array $param = null): array
    {
        try {
            // Set request options
            $options = [
                'headers' => $this->getHeader(),
            ];

            // Include body for POST/PUT requests
            if ($param !== null) {
                $options['body'] = json_encode($param, true);
            }

            // Send the request
            $response = $this->httpClient->request($method, $url, $options);

            // Decode and return JSON response
            return json_decode($response->getContent(), true);
        } catch (ClientException | RedirectionException | ServerException | TransportException | HttpExceptionInterface | DecodingExceptionInterface | TransportExceptionInterface | \Exception $e) {
            // Handle all errors and return as array
            return $this->handleZitadelError($e);
        }
    }
}
