<?php

declare(strict_types=1);

namespace Apify\Client\Model;

use Apify\Client\Internal\UrlNormalizer;

/**
 * Base class for API resource models.
 *
 * Each model wraps the raw decoded JSON object and exposes commonly-used fields as typed getters.
 * The full payload — including any field the API adds that is not modelled here — is always
 * available via {@see toArray()} and {@see get()}, so additive API changes never lose data.
 *
 * The fields a model lists in {@see URL_FIELDS} (those the OpenAPI specification declares as
 * {@code format: uri}) are normalized to the WHATWG URL serialization when the model is built, the same
 * way the reference client does: a trailing slash is added to an empty path, the scheme and host are
 * lowercased, a default port is dropped and unsafe characters are percent-encoded. A value that is not
 * an absolute http(s)/ws(s)/ftp URL is kept as received.
 */
abstract class ApifyResource
{
    /**
     * The URL fields of this resource: dot-separated key paths into the raw object, where {@code *}
     * stands for every entry of an object.
     *
     * @var list<string>
     */
    protected const URL_FIELDS = [];

    /**
     * @param array<string,mixed> $data the raw decoded resource object
     */
    public function __construct(protected array $data)
    {
        $this->data = UrlNormalizer::normalizeFields($data, static::URL_FIELDS);
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
