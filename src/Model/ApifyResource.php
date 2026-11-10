<?php

declare(strict_types=1);

namespace Apify\Client\Model;

use Apify\Client\Internal\UrlNormalizer;

/**
 * Base class of the response models. A model wraps the decoded JSON object and has getters for common fields.
 * {@see toArray()} and {@see get()} return every field, including those the model does not list.
 *
 * The {@see URL_FIELDS} of a model, which the OpenAPI specification declares as {@code format: uri}, are
 * normalized to the WHATWG URL serialization when the model is built. Other values are kept as received.
 */
abstract class ApifyResource
{
    /**
     * The URL fields: dot-separated key paths into the object, where {@code *} matches every entry of an object.
     *
     * @var list<string>
     */
    protected const URL_FIELDS = [];

    /**
     * @param array<string,mixed> $data the decoded resource object
     */
    public function __construct(protected array $data)
    {
        $this->data = UrlNormalizer::normalizeFields($data, static::URL_FIELDS);
    }

    /**
     * The whole resource object, including fields without a getter.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * A field by key, or {@code null} if it is absent.
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
