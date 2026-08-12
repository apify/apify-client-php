<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * A single request stored in a request queue. Fields left {@code null} are omitted when the request
 * is sent to the API. Construct one for adding to a queue, or receive one when reading a queue.
 */
final class RequestQueueRequest extends ApifyResource
{
    /**
     * @param array<string,mixed> $data raw data (used when hydrating from the API)
     */
    public function __construct(?string $url = null, ?string $uniqueKey = null, array $data = [])
    {
        if ($url !== null) {
            $data['url'] = $url;
        }
        if ($uniqueKey !== null) {
            $data['uniqueKey'] = $uniqueKey;
        }
        parent::__construct($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(null, null, $data);
    }

    /** The unique request ID (assigned by the API; absent on create). */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    public function setId(string $id): self
    {
        $this->data['id'] = $id;
        return $this;
    }

    /** The request URL. */
    public function getUrl(): ?string
    {
        return $this->getString('url');
    }

    public function setUrl(string $url): self
    {
        $this->data['url'] = $url;
        return $this;
    }

    /** The deduplication key for the request. */
    public function getUniqueKey(): ?string
    {
        return $this->getString('uniqueKey');
    }

    public function setUniqueKey(string $uniqueKey): self
    {
        $this->data['uniqueKey'] = $uniqueKey;
        return $this;
    }

    /** The HTTP method (e.g. {@code "GET"}, {@code "POST"}). */
    public function getMethod(): ?string
    {
        return $this->getString('method');
    }

    public function setMethod(string $method): self
    {
        $this->data['method'] = $method;
        return $this;
    }

    /**
     * Arbitrary user-attached metadata.
     *
     * @return mixed
     */
    public function getUserData(): mixed
    {
        return $this->get('userData');
    }

    public function setUserData(mixed $userData): self
    {
        $this->data['userData'] = $userData;
        return $this;
    }

    /**
     * How many times processing this request has already been retried. Populated on requests
     * returned by {@see \Apify\Client\Resource\RequestQueueClient::listHead()},
     * {@see \Apify\Client\Resource\RequestQueueClient::listAndLockHead()} and
     * {@see \Apify\Client\Resource\RequestQueueClient::listRequests()}; absent when constructing a
     * request to add.
     */
    public function getRetryCount(): ?int
    {
        return $this->getInt('retryCount');
    }

    /**
     * ISO 8601 timestamp of when this request's processing lock expires. Only present on requests
     * returned by {@see \Apify\Client\Resource\RequestQueueClient::listAndLockHead()}.
     */
    public function getLockExpiresAt(): ?string
    {
        return $this->getString('lockExpiresAt');
    }
}
