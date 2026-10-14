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
 * Class SkeletonListCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class SkeletonListCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function listsTheShippedSkeletons(): void
    {
        $tester = $this->runCommand($this->skeletonListCommand, ['--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Available skeletons for "demo_shop"', $tester->getDisplay());
        self::assertStringContainsString('Php/FileHeader', $tester->getDisplay());
        self::assertStringContainsString('Overrides default', $tester->getDisplay());
    }

    #[Test]
    public function marksOverriddenSkeletons(): void
    {
        $root = rtrim(Environment::getProjectPath(), '/') . '/config/Crafter/Skeletons/';
        @mkdir($root . 'Php/', 0777, true);
        file_put_contents($root . 'Php/FileHeader.stub', "// override\n");

        $tester = $this->runCommand($this->skeletonListCommand, ['--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('config/Crafter/Skeletons/Php/FileHeader.stub', $tester->getDisplay());
        self::assertStringContainsString('yes', $tester->getDisplay());
    }
}
