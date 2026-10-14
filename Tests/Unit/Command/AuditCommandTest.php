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

use function file_put_contents;
use function unlink;

/**
 * Class AuditCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class AuditCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function requiresAnExtension(): void
    {
        $tester = $this->runCommand($this->auditCommand);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No extension given', $tester->getDisplay());
    }

    #[Test]
    public function reportsACleanExtension(): void
    {
        $this->generateCompleteExtension();

        $tester = $this->runCommand($this->auditCommand, ['extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No findings', $tester->getDisplay());
    }

    #[Test]
    public function failsOnErrorFindings(): void
    {
        $this->generateCompleteExtension();
        unlink($this->getExtensionFilePath('Classes/Data/ExtensionInformation.php'));

        $tester = $this->runCommand($this->auditCommand, ['extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ExtensionInformation.php', $tester->getDisplay());
        self::assertStringContainsString('1 error(s)', $tester->getDisplay());
    }

    #[Test]
    public function warningsAreNotFatalWithoutStrict(): void
    {
        $this->generateCompleteExtension();
        unlink($this->getExtensionFilePath('Build/Quality/.yamllint.yml'));

        $tester = $this->runCommand($this->auditCommand, ['extensionKey' => self::EXTENSION_KEY]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 warning(s)', $tester->getDisplay());
    }

    #[Test]
    public function strictFailsOnWarningFindings(): void
    {
        $this->generateCompleteExtension();
        unlink($this->getExtensionFilePath('Build/Quality/.yamllint.yml'));

        $tester = $this->runCommand($this->auditCommand, ['extensionKey' => self::EXTENSION_KEY, '--strict' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 warning(s)', $tester->getDisplay());
    }

    #[Test]
    public function categoryFilterLimitsTheAudit(): void
    {
        $this->generateCompleteExtension();
        unlink($this->getExtensionFilePath('Classes/Data/ExtensionInformation.php'));
        file_put_contents(
            $this->getExtensionFilePath('Build/Quality/phpstan.neon.dist'),
            file_get_contents($this->getExtensionFilePath('Build/Quality/phpstan.neon.dist')) . "\n# drift\n"
        );

        $tester = $this->runCommand($this->auditCommand, ['extensionKey' => self::EXTENSION_KEY, '--category' => ['toolchain']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('phpstan.neon.dist', $tester->getDisplay());
        self::assertStringContainsString('0 error(s)', $tester->getDisplay());
    }
}
