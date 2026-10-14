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

use function file_get_contents;

/**
 * Class MakeServiceCommandTest
 *
 * Doubles as the test for the shared behaviour of AbstractMakeClassCommand: name validation, sub path validation,
 * dependency resolution, dry run and the up-to-date detection.
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeServiceCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAServiceWithResolvedDependencies(): void
    {
        $this->generateBaseExtension();

        $tester = $this->runCommand($this->makeServiceCommand, [
            'name'         => 'Price',
            '--extension'  => self::EXTENSION_KEY,
            '--dependency' => ['Context', 'ProductRepository'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $service = (string)file_get_contents($this->getExtensionFilePath('Classes/Service/PriceService.php'));
        self::assertStringContainsString('TYPO3\CMS\Core\Context\Context', $service);
        self::assertStringContainsString('PSBits\DemoShop\Domain\Repository\ProductRepository', $service);
        self::assertStringContainsString('Service "PriceService" has been created.', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnresolvableShortDependency(): void
    {
        $this->generateBaseExtension();

        $tester = $this->runCommand($this->makeServiceCommand, [
            'name'         => 'Price',
            '--extension'  => self::EXTENSION_KEY,
            '--dependency' => ['Bogus'],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Dependency "Bogus" cannot be resolved', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnInvalidName(): void
    {
        $tester = $this->runCommand($this->makeServiceCommand, ['name' => 'price', '--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UpperCamelCase', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnInvalidSubPath(): void
    {
        $tester = $this->runCommand($this->makeServiceCommand, [
            'name'        => 'Price',
            '--extension' => self::EXTENSION_KEY,
            '--sub-path'  => 'lower/case',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UpperCamelCase segments', $tester->getDisplay());
    }

    #[Test]
    public function reportsAnUpToDateClassOnTheSecondRun(): void
    {
        $this->generateBaseExtension();
        $input = ['name' => 'Price', '--extension' => self::EXTENSION_KEY];

        self::assertSame(Command::SUCCESS, $this->runCommand($this->makeServiceCommand, $input)->getStatusCode());

        $tester = $this->runCommand($this->makeServiceCommand, $input);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already up to date', $tester->getDisplay());
    }

    #[Test]
    public function dryRunWritesNoFile(): void
    {
        $this->generateBaseExtension();

        $tester = $this->runCommand($this->makeServiceCommand, [
            'name'        => 'Price',
            '--extension' => self::EXTENSION_KEY,
            '--dry-run'   => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->getExtensionFilePath('Classes/Service/PriceService.php'));
    }

    #[Test]
    public function requiresTheExtensionOption(): void
    {
        $tester = $this->runCommand($this->makeServiceCommand, ['name' => 'Price']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The option --extension is required.', $tester->getDisplay());
    }
}
