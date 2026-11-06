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
     * The Actor's directory inside the uploaded source, as a path relative to the upload root, for a
     * {@code SOURCE_FILES} or {@code TARBALL} version, or {@code null} when the upload root is the Actor's
     * directory. On a {@code TARBALL} version, only users with write access to the Actor get it.
     */
    public function getActorPath(): ?string
    {
        return $this->getString('actorPath');
    }
}
