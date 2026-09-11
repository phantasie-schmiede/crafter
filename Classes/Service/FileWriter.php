<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Enum\WriteStatus;
use PSBits\Crafter\Event\AfterGenerationEvent;
use PSBits\Crafter\Event\BeforeFileWriteEvent;
use PSBits\Crafter\Exception\CrafterException;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function sprintf;

/**
 * Class FileWriter
 *
 * Single point of file creation for all generators. Never overwrites silently and collects every result so commands
 * can print a summary and support --dry-run.
 *
 * @package PSBits\Crafter\Service
 */
class FileWriter
{
    private bool $dryRun = false;

    private bool $force = false;

    /** @var WriteResult[] */
    private array $results = [];

    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Closes a generation run and informs listeners about everything that has been written.
     */
    public function finish(string $command, ?string $extensionKey = null): void
    {
        $this->eventDispatcher->dispatch(
            new AfterGenerationEvent($command, $extensionKey, $this->results, $this->dryRun)
        );
    }

    /**
     * @return WriteResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Writes a modification of an existing file. In contrast to write() the file is always updated, because the
     * content is derived from the file itself and adding something twice is prevented by the caller.
     */
    public function modify(string $absoluteFilePath, string $content): WriteResult
    {
        return $this->process($absoluteFilePath, $content, true);
    }

    public function reset(bool $dryRun = false, bool $force = false): void
    {
        $this->dryRun  = $dryRun;
        $this->force   = $force;
        $this->results = [];
    }

    public function write(string $absoluteFilePath, string $content): WriteResult
    {
        return $this->process($absoluteFilePath, $content, false);
    }

    private function determineStatus(string $absoluteFilePath, string $content, bool $modification): WriteStatus
    {
        if (!is_file($absoluteFilePath)) {
            return WriteStatus::created;
        }

        if ($content === file_get_contents($absoluteFilePath)) {
            return WriteStatus::unchanged;
        }

        // A modification is derived from the file itself, so it is never guarded by --force.
        if (true === $modification) {
            return WriteStatus::overwritten;
        }

        return true === $this->force ? WriteStatus::overwritten : WriteStatus::skipped;
    }

    private function process(string $absoluteFilePath, string $content, bool $modification): WriteResult
    {
        $event = new BeforeFileWriteEvent(
            $absoluteFilePath,
            $content,
            $modification,
            $this->dryRun,
            $this->force
        );
        $this->eventDispatcher->dispatch($event);
        $absoluteFilePath = $event->getPath();
        $content          = $event->getContent();
        $status           = true === $event->isSkipped()
            ? WriteStatus::skipped
            : $this->determineStatus($absoluteFilePath, $content, $modification);

        if (false === $this->dryRun && true === $status->hasChangedFile()) {
            $this->putContents($absoluteFilePath, $content);
        }

        $result          = new WriteResult($absoluteFilePath, $status, $this->dryRun);
        $this->results[] = $result;

        return $result;
    }

    private function putContents(string $absoluteFilePath, string $content): void
    {
        $directory = dirname($absoluteFilePath);

        if (!is_dir($directory)) {
            GeneralUtility::mkdir_deep($directory);
        }

        if (false === GeneralUtility::writeFile($absoluteFilePath, $content, true)) {
            throw new CrafterException(
                sprintf('File "%s" could not be written.', $absoluteFilePath),
                1757426408
            );
        }
    }
}
