<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A single key listed from a key-value store. */
final class KeyValueStoreKey extends ApifyResource
{
    protected const URL_FIELDS = ['recordPublicUrl'];

    /** The record key. */
    public function getKey(): ?string
    {
        return $this->getString('key');
    }

    /** The record size in bytes. */
    public function getSize(): ?int
    {
        return $this->getInt('size');
    }
}
