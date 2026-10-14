<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Class SkeletonPublishCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class SkeletonPublishCommandTest extends AbstractCommandTestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = rtrim(Environment::getProjectPath(), '/') . '/config/Crafter/Skeletons/';
    }

    #[Test]
    public function copiesAShippedSkeletonIntoTheProjectRoot(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, ['identifier' => 'Php/FileHeader']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->projectRoot . 'Php/FileHeader.stub');
        self::assertStringContainsString('config/Crafter/Skeletons/Php/FileHeader.stub', $tester->getDisplay());
    }

    #[Test]
    public function reportsAnAlreadyPublishedSkeleton(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            'identifier' => 'Php/FileHeader',
            '--priority' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('is already published at priority 0', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnknownPriority(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            'identifier' => 'Php/FileHeader',
            '--priority' => '99',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No skeleton root path configured for priority 99', $tester->getDisplay());
        self::assertStringContainsString('Available priorities', $tester->getDisplay());
    }

    #[Test]
    public function dryRunWritesNoFile(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            'identifier' => 'Php/FileHeader',
            '--dry-run'  => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectRoot . 'Php/FileHeader.stub');
    }
}
