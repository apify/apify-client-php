<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * Base class for API resource models.
 *
 * Each model wraps the raw decoded JSON object and exposes commonly-used fields as typed getters.
 * The full payload — including any field the API adds that is not modelled here — is always
 * available via {@see toArray()} and {@see get()}, so additive API changes never lose data.
 */
abstract class ApifyResource
{
    /**
     * @param array<string,mixed> $data the raw decoded resource object
     */
    public function __construct(protected array $data)
    {
    }

    /**
     * The full raw resource object, including fields not mapped to a typed getter.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * A single raw field by key ({@code null} if absent).
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    protected function getString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        return (is_int($value) || is_float($value)) ? (string) $value : null;
    }

    protected function getInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    protected function getBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;
        return is_bool($value) ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    protected function getStringList(string $key): ?array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }
        return $strings;
    }
}
