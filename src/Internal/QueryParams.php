<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * An ordered collection of query parameters that omits absent ({@code null}) values and encodes
 * booleans as {@code 1}/{@code 0}, matching the Apify API conventions.
 *
 * @internal
 */
final class QueryParams
{
    /** @var list<array{0:string,1:string}> */
    private array $pairs = [];

    /** Adds a string parameter if the value is non-null. */
    public function addString(string $key, ?string $value): self
    {
        if ($value !== null) {
            $this->pairs[] = [$key, $value];
        }
        return $this;
    }

    /** Adds an integer parameter if the value is non-null. */
    public function addInt(string $key, ?int $value): self
    {
        if ($value !== null) {
            $this->pairs[] = [$key, (string) $value];
        }
        return $this;
    }

    /** Adds a floating-point parameter if the value is non-null. */
    public function addFloat(string $key, ?float $value): self
    {
        if ($value !== null) {
            // Use a locale-independent representation without a trailing ".0" for whole numbers.
            $this->pairs[] = [$key, rtrim(rtrim(sprintf('%.10F', $value), '0'), '.')];
        }
        return $this;
    }

    /**
     * Adds a boolean parameter, encoded as {@code 1}/{@code 0}, if the value is non-null. This
     * matches the JS reference client, whose axios {@code paramsSerializer} converts booleans via
     * {@code Number(value)} (so {@code true}→{@code 1}, {@code false}→{@code 0}).
     */
    public function addBool(string $key, ?bool $value): self
    {
        if ($value !== null) {
            $this->pairs[] = [$key, $value ? '1' : '0'];
        }
        return $this;
    }

    /**
     * Adds a comma-joined list parameter if the list is non-null and non-empty.
     *
     * @param list<string>|null $values
     */
    public function addCsv(string $key, ?array $values): self
    {
        if ($values !== null && $values !== []) {
            $this->pairs[] = [$key, implode(',', $values)];
        }
        return $this;
    }

    /** Appends an already-stringified key/value pair unconditionally. */
    public function addRaw(string $key, string $value): self
    {
        $this->pairs[] = [$key, $value];
        return $this;
    }

    /** Returns a shallow copy of this instance. */
    public function copy(): self
    {
        $out = new self();
        $out->pairs = $this->pairs;
        return $out;
    }

    /** Appends all pairs from {@code $other} to this instance. */
    public function extend(?QueryParams $other): self
    {
        if ($other !== null) {
            $this->pairs = array_merge($this->pairs, $other->pairs);
        }
        return $this;
    }

    /** Appends the parameters to {@code $rawUrl} as a URL-encoded query string. */
    public function applyToUrl(string $rawUrl): string
    {
        if ($this->pairs === []) {
            return $rawUrl;
        }
        $parts = [];
        foreach ($this->pairs as [$key, $value]) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $sep = str_contains($rawUrl, '?') ? '&' : '?';
        return $rawUrl . $sep . implode('&', $parts);
    }
}
