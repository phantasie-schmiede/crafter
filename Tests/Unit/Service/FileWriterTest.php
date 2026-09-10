<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PSBits\Crafter\Enum\WriteStatus;
use PSBits\Crafter\Event\AfterGenerationEvent;
use PSBits\Crafter\Event\BeforeFileWriteEvent;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Tests\Fixtures\CallbackEventDispatcher;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;

/**
 * Class FileWriterTest
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class FileWriterTest extends AbstractCrafterTestCase
{
    #[Test]
    public function dryRunReportsTheStatusWithoutTouchingTheDisk(): void
    {
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $this->fileWriter->reset(true);
        $result = $this->fileWriter->write($path, 'content');

        self::assertSame(WriteStatus::created, $result->status);
        self::assertTrue($result->dryRun);
        self::assertFileDoesNotExist($path);
    }

    #[Test]
    public function existingFileIsSkippedWithoutForce(): void
    {
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $this->fileWriter->write($path, 'original');

        self::assertSame(WriteStatus::skipped, $this->fileWriter->write($path, 'changed')->status);
        self::assertStringEqualsFile($path, 'original');
    }

    #[Test]
    public function finishDispatchesTheAfterGenerationEvent(): void
    {
        $dispatcher = new CallbackEventDispatcher();
        $fileWriter = new FileWriter($dispatcher);
        $fileWriter->reset();
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $fileWriter->write($path, 'content');
        $fileWriter->finish('crafter:make:model', self::EXTENSION_KEY);

        /** @var AfterGenerationEvent $event */
        $event = $dispatcher->getDispatchedEvents(AfterGenerationEvent::class)[0];

        self::assertSame('crafter:make:model', $event->getCommand());
        self::assertSame(self::EXTENSION_KEY, $event->getExtensionKey());
        self::assertSame([$path], $event->getChangedPaths());
    }

    #[Test]
    public function forceOverwritesAnExistingFile(): void
    {
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $this->fileWriter->write($path, 'original');
        $this->fileWriter->reset(false, true);

        self::assertSame(WriteStatus::overwritten, $this->fileWriter->write($path, 'changed')->status);
        self::assertStringEqualsFile($path, 'changed');
    }

    #[Test]
    public function identicalContentIsReportedAsUnchanged(): void
    {
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $this->fileWriter->write($path, 'content');

        self::assertSame(WriteStatus::unchanged, $this->fileWriter->write($path, 'content')->status);
    }

    #[Test]
    public function listenerCanRewriteThePathAndTheContent(): void
    {
        $target     = $this->getExtensionFilePath('Classes/Domain/Model/Renamed.php');
        $dispatcher = new CallbackEventDispatcher();
        $dispatcher->addListener(static function(object $event) use ($target): void {
            if ($event instanceof BeforeFileWriteEvent) {
                $event->setContent('patched');
                $event->setPath($target);
            }
        });
        $fileWriter = new FileWriter($dispatcher);
        $fileWriter->reset();
        $fileWriter->write($this->getExtensionFilePath('Classes/Domain/Model/Product.php'), 'content');

        self::assertStringEqualsFile($target, 'patched');
    }

    #[Test]
    public function listenerCanSkipAFile(): void
    {
        $dispatcher = new CallbackEventDispatcher();
        $dispatcher->addListener(static function(object $event): void {
            if ($event instanceof BeforeFileWriteEvent) {
                $event->skip();
            }
        });
        $fileWriter = new FileWriter($dispatcher);
        $fileWriter->reset();
        $path = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');

        self::assertSame(WriteStatus::skipped, $fileWriter->write($path, 'content')->status);
        self::assertFileDoesNotExist($path);
    }

    #[Test]
    public function modificationIsWrittenEvenWithoutForce(): void
    {
        $path = $this->getExtensionFilePath('Classes/Data/ExtensionInformation.php');
        $this->fileWriter->write($path, 'original');

        self::assertSame(WriteStatus::overwritten, $this->fileWriter->modify($path, 'changed')->status);
        self::assertStringEqualsFile($path, 'changed');
    }

    #[Test]
    public function newFileIsCreatedIncludingItsDirectories(): void
    {
        $path   = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        $result = $this->fileWriter->write($path, 'content');

        self::assertSame(WriteStatus::created, $result->status);
        self::assertSame($path, $result->path);
        self::assertStringEqualsFile($path, 'content');
    }

    #[Test]
    public function resetClearsThePreviousResults(): void
    {
        $this->fileWriter->write($this->getExtensionFilePath('a.txt'), 'a');
        self::assertCount(1, $this->fileWriter->getResults());

        $this->fileWriter->reset();
        self::assertSame([], $this->fileWriter->getResults());
    }
}
