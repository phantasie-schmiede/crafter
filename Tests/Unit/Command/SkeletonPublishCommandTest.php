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
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\MockObject\Exception;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use TYPO3\CMS\Core\Core\Environment;

final class SkeletonPublishCommandTest extends AbstractCommandTestCase
{
    private string $projectRoot;

    /**
     * @throws Exception
     * @throws LogicException
     * @throws RandomException
     * @throws RuntimeException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = rtrim(Environment::getProjectPath(), '/') . '/config/Crafter/Skeletons/';
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function copiesAShippedSkeletonIntoTheProjectRoot(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, ['identifier' => 'Php/FileHeader']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->projectRoot . 'Php/FileHeader.stub');
        self::assertStringContainsString('config/Crafter/Skeletons/Php/FileHeader.stub', $tester->getDisplay());
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function dryRunWritesNoFile(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            '--dry-run'  => true,
            'identifier' => 'Php/FileHeader',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectRoot . 'Php/FileHeader.stub');
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function rejectsAnUnknownPriority(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            '--priority' => '99',
            'identifier' => 'Php/FileHeader',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No skeleton root path configured for priority 99', $tester->getDisplay());
        self::assertStringContainsString('Available priorities', $tester->getDisplay());
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function reportsAnAlreadyPublishedSkeleton(): void
    {
        $tester = $this->runCommand($this->skeletonPublishCommand, [
            '--priority' => '0',
            'identifier' => 'Php/FileHeader',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('is already published at priority 0', $tester->getDisplay());
    }
}
