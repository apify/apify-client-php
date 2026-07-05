<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** An environment variable attached to an Actor version. */
final class ActorEnvVar extends ApifyResource
{
    /**
     * @param array<string,mixed> $data raw data (used when hydrating from the API)
     */
    public function __construct(?string $name = null, ?string $value = null, ?bool $isSecret = null, array $data = [])
    {
        if ($name !== null) {
            $data['name'] = $name;
        }
        if ($value !== null) {
            $data['value'] = $value;
        }
        if ($isSecret !== null) {
            $data['isSecret'] = $isSecret;
        }
        parent::__construct($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(null, null, null, $data);
    }

    /** The environment variable name. */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The environment variable value. */
    public function getValue(): ?string
    {
        return $this->getString('value');
    }

    /** Whether the value is stored as a secret. */
    public function getIsSecret(): ?bool
    {
        return $this->getBool('isSecret');
    }
}
