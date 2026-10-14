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
 * Class MakeViewHelperCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeViewHelperCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAViewHelperWithArgumentRegistrationInASubPath(): void
    {
        $tester = $this->runCommand($this->makeViewHelperCommand, [
            'name'        => 'Price',
            '--extension' => self::EXTENSION_KEY,
            '--sub-path'  => 'Format',
            '--argument'  => ['price:integer:1:The price to format'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $viewHelper = (string)file_get_contents($this->getExtensionFilePath('Classes/ViewHelpers/Format/PriceViewHelper.php'));
        self::assertStringContainsString("registerArgument('price', 'integer', 'The price to format', true)", $viewHelper);
        self::assertStringContainsString('public function render(): string', $viewHelper);
        self::assertStringContainsString("return '';", $viewHelper);
    }

    #[Test]
    public function rejectsAnUnknownArgumentType(): void
    {
        $tester = $this->runCommand($this->makeViewHelperCommand, [
            'name'        => 'Price',
            '--extension' => self::EXTENSION_KEY,
            '--argument'  => ['price:bogus'],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown argument type "bogus"', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnknownReturnType(): void
    {
        $tester = $this->runCommand($this->makeViewHelperCommand, [
            'name'          => 'Price',
            '--extension'   => self::EXTENSION_KEY,
            '--return-type' => 'bogus',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown return type "bogus"', $tester->getDisplay());
    }
}
