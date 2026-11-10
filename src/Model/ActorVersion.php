<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A single version of an Actor. */
final class ActorVersion extends ApifyResource
{
    /** The version identifier (e.g. {@code "0.1"}). */
    public function getVersionNumber(): ?string
    {
        return $this->getString('versionNumber');
    }

    /** How the version's source is provided (e.g. {@code "SOURCE_FILES"}). */
    public function getSourceType(): ?string
    {
        return $this->getString('sourceType');
    }

    /**
     * The directory of the Actor inside the uploaded source, relative to the upload root, for a
     * {@code SOURCE_FILES} or {@code TARBALL} version. {@code null} when the upload root is that directory. A
     * {@code TARBALL} version gives it only to users with write access to the Actor.
     */
    public function getActorPath(): ?string
    {
        return $this->getString('actorPath');
    }
}
