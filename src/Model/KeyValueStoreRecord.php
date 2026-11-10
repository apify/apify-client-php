<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A record of a key-value store. {@see getValue()} holds the raw bytes, to decode by {@see getContentType()}. */
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
