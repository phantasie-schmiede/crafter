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

final class MakeBackendControllerCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsABackendControllerWithModuleRegistration(): void
    {
        $this->generateBaseExtension();

        $tester = $this->runCommand($this->makeBackendControllerCommand, [
            '--actions'   => 'index,run',
            '--extension' => self::EXTENSION_KEY,
            'name'        => 'Import',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/Backend/ImportController.php'));
        self::assertFileExists($this->getExtensionFilePath('Resources/Private/Templates/Backend/Import/Index.html'));
        self::assertFileExists($this->getExtensionFilePath('Configuration/Backend/Modules.php'));
    }

    #[Test]
    public function noModuleFlagSkipsTheRegistration(): void
    {
        $this->generateBaseExtension();

        $tester = $this->runCommand($this->makeBackendControllerCommand, [
            '--actions'   => 'index',
            '--extension' => self::EXTENSION_KEY,
            '--no-module' => true,
            'name'        => 'Import',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Controller/Backend/ImportController.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Configuration/Backend/Modules.php'));
    }
}
