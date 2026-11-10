<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A request in a request queue. Fields left {@code null} are not sent to the API. */
final class RequestQueueRequest extends ApifyResource
{
    /**
     * @param array<string,mixed> $data the raw data, when built from an API response
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
     * User-attached metadata.
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
     * How many times the request was retried. Set on requests from {@see \Apify\Client\Resource\RequestQueueClient::listHead()},
     * {@see \Apify\Client\Resource\RequestQueueClient::listAndLockHead()} and
     * {@see \Apify\Client\Resource\RequestQueueClient::listRequests()}.
     */
    public function getRetryCount(): ?int
    {
        return $this->getInt('retryCount');
    }

    /**
     * ISO 8601 time when the lock of the request expires. Set on requests from
     * {@see \Apify\Client\Resource\RequestQueueClient::listAndLockHead()}.
     */
    public function getLockExpiresAt(): ?string
    {
        return $this->getString('lockExpiresAt');
    }
}
