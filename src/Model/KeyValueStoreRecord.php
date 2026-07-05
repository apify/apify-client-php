<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * A single record retrieved from a key-value store. Its {@see getValue() value} holds the raw bytes
 * (as a string); callers can decode it according to {@see getContentType() content type}.
 */
final class KeyValueStoreRecord
{
    public function __construct(
        private string $key,
        private string $value,
        private ?string $contentType,
    ) {
    }

    /** The record key. */
    public function getKey(): string
    {
        return $this->key;
    }

    /** The raw record bytes, as a string. */
    public function getValue(): string
    {
        return $this->value;
    }

    /** The record's MIME type, as reported by the API. */
    public function getContentType(): ?string
    {
        return $this->contentType;
    }
}
