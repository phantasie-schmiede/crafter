<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Event;

/**
 * Class BeforeFileWriteEvent
 *
 * Last chance to influence a generated file. Listeners can rewrite the content, redirect it to another target path
 * or skip it entirely, e.g. to keep a file out of a specific extension.
 *
 * @package PSBits\Crafter\Event
 */
final class BeforeFileWriteEvent
{
    private bool $skipped = false;

    public function __construct(
        private string        $path,
        private string        $content,
        private readonly bool $modification,
        private readonly bool $dryRun,
        private readonly bool $force,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    /**
     * True if an existing file is updated by an injection instead of being written from scratch.
     */
    public function isModification(): bool
    {
        return $this->modification;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * Prevents the file from being written. It is still reported with the status "skipped".
     */
    public function skip(): void
    {
        $this->skipped = true;
    }
}
