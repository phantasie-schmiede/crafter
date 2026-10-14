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

/**
 * Class ConfigDumpCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class ConfigDumpCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function dumpsTheEffectiveConfigurationWithItsLayers(): void
    {
        $tester = $this->runCommand($this->configDumpCommand, ['--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Crafter configuration for "demo_shop"', $tester->getDisplay());
        self::assertStringContainsString('Layers (later wins)', $tester->getDisplay());
        self::assertStringContainsString('crafter (default)', $tester->getDisplay());
        self::assertStringContainsString('Effective configuration', $tester->getDisplay());
        self::assertStringContainsString("author: 'Daniel Ablass'", $tester->getDisplay());
    }

    #[Test]
    public function listsTheOriginOfEveryValue(): void
    {
        $this->writeProjectConfiguration("crafter:\n  defaults:\n    author: Jane Doe\n");

        $tester = $this->runCommand($this->configDumpCommand, ['--extension' => self::EXTENSION_KEY, '--origins' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Value origins', $tester->getDisplay());
        self::assertStringContainsString('crafter (default)', $tester->getDisplay());
        self::assertStringContainsString('project', $tester->getDisplay());
    }

    #[Test]
    public function theProjectLayerWinsOverTheDefaults(): void
    {
        $this->writeProjectConfiguration("crafter:\n  defaults:\n    author: Jane Doe\n");

        $tester = $this->runCommand($this->configDumpCommand, ['--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("author: 'Jane Doe'", $tester->getDisplay());
        self::assertStringContainsString('project', $tester->getDisplay());
    }
}
