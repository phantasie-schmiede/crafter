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
 * Class MakeControllerCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeControllerCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAControllerWithTheSelectedActionsOnly(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
            '--actions'   => 'list,show',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/ProductController.php'));
        self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'));
        self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/Show.html'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Resources/Private/Templates/Product/Edit.html'));
        self::assertStringContainsString('Controller "ProductController" has been created.', $tester->getDisplay());
    }

    #[Test]
    public function crudKeywordGeneratesEveryConfiguredAction(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
            '--actions'   => 'crud',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        foreach (['List', 'Show', 'New', 'Edit'] as $action) {
            self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/' . $action . '.html'));
        }
    }

    #[Test]
    public function noTemplatesFlagSkipsTheFluidTemplates(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            'name'           => 'Product',
            '--extension'    => self::EXTENSION_KEY,
            '--actions'      => 'list',
            '--no-templates' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/ProductController.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'));
    }

    #[Test]
    public function rejectsAnInvalidName(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, ['name' => 'product', '--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UpperCamelCase', $tester->getDisplay());
    }
}
