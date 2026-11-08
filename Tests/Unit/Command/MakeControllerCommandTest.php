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
use RuntimeException;
use Symfony\Component\Console\Command\Command;

final class MakeControllerCommandTest extends AbstractCommandTestCase
{
    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function createsAControllerWithTheSelectedActionsOnly(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            '--actions'   => 'list,show',
            '--extension' => self::EXTENSION_KEY,
            'name'        => 'Product',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/ProductController.php'));
        self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'));
        self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/Show.html'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Resources/Private/Templates/Product/Edit.html'));
        self::assertStringContainsString('Controller "ProductController" has been created.', $tester->getDisplay());
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function crudKeywordGeneratesEveryConfiguredAction(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            '--actions'   => 'crud',
            '--extension' => self::EXTENSION_KEY,
            'name'        => 'Product',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        foreach (['List', 'Show', 'New', 'Edit'] as $action) {
            self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Product/' . $action . '.html'));
        }
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function noTemplatesFlagSkipsTheFluidTemplates(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, [
            '--actions'      => 'list',
            '--extension'    => self::EXTENSION_KEY,
            '--no-templates' => true,
            'name'           => 'Product',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/ProductController.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Resources/Private/Templates/Product/List.html'));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function rejectsAnInvalidName(): void
    {
        $tester = $this->runCommand($this->makeControllerCommand, ['--extension' => self::EXTENSION_KEY, 'name' => 'product']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UpperCamelCase', $tester->getDisplay());
    }
}
