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
use function file_put_contents;

final class SyncCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function acceptsTheExtensionViaOption(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');

        $tester = $this->runCommand($this->syncCommand, ['--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Build/Quality/.php-cs-fixer.dist.php'));
    }

    #[Test]
    public function dryRunLeavesDriftedFilesUntouched(): void
    {
        $this->generateBaseExtension(['core', 'typoScript', 'quality', 'ci']);
        $path    = $this->getExtensionFilePath('Build/Quality/.php-cs-fixer.dist.php');
        $drifted = file_get_contents($path) . "// drift\n";
        file_put_contents($path, $drifted);

        $tester = $this->runCommand($this->syncCommand, ['--dry-run' => true, 'extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertSame($drifted, (string)file_get_contents($path));
    }

    #[Test]
    public function renewsADriftedQualityConfig(): void
    {
        $this->generateBaseExtension(['core', 'typoScript', 'quality', 'ci']);
        $path = $this->getExtensionFilePath('Build/Quality/.php-cs-fixer.dist.php');
        file_put_contents($path, file_get_contents($path) . "// drift\n");

        $tester = $this->runCommand($this->syncCommand, ['extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('CsFixerFactory', (string)file_get_contents($path));
    }

    #[Test]
    public function reportsNothingToDoForAnInSyncExtension(): void
    {
        $this->generateBaseExtension(['core', 'typoScript', 'quality', 'ci']);

        $tester = $this->runCommand($this->syncCommand, ['extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
    }

    #[Test]
    public function requiresAnExtension(): void
    {
        $tester = $this->runCommand($this->syncCommand);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No extension given', $tester->getDisplay());
    }
}
