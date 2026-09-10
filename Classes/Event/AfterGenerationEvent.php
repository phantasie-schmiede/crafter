<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Event;

use PSBits\Crafter\Data\WriteResult;

/**
 * Class AfterGenerationEvent
 *
 * Dispatched when a command has written all its files. Typical listeners run a code formatter over the created
 * files, add them to the index of a version control system or trigger a cache flush.
 *
 * @package PSBits\Crafter\Event
 */
final class AfterGenerationEvent
{
    /**
     * @param WriteResult[] $results
     */
    public function __construct(
        private readonly string  $command,
        private readonly ?string $extensionKey,
        private readonly array   $results,
        private readonly bool    $dryRun,
    ) {
    }

    /**
     * Paths of all files that have actually been created or overwritten.
     *
     * @return string[]
     */
    public function getChangedPaths(): array
    {
        $paths = [];

        foreach ($this->results as $result) {
            if (true === $result->status->hasChangedFile()) {
                $paths[] = $result->path;
            }
        }

        return $paths;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getExtensionKey(): ?string
    {
        return $this->extensionKey;
    }

    /**
     * @return WriteResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
