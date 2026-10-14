<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;

use function file_put_contents;
use function json_decode;

/**
 * Class MakeExtensionCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeExtensionCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAnExtensionFromExplicitOptions(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, [
            'extensionKey'      => 'budget_tracker',
            '--vendor'          => 'Acme',
            '--composer-name'   => 'acme/budget-tracker',
            '--title'           => 'Budget Tracker',
            '--description'     => 'Tracks budgets',
            '--author'          => 'Jane Doe',
            '--author-email'    => 'jane@example.com',
            '--license'         => 'MIT',
            '--category'        => 'plugin',
            '--state'           => 'stable',
            '--package-version' => '1.2.3',
            '--groups'          => 'core,ci',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->pathOf('budget_tracker', 'composer.json'));
        self::assertFileExists($this->pathOf('budget_tracker', 'ext_emconf.php'));
        self::assertFileExists($this->pathOf('budget_tracker', '.github/workflows/quality.yml'));
        $composer = json_decode((string)file_get_contents($this->pathOf('budget_tracker', 'composer.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('acme/budget-tracker', $composer['name']);
        self::assertSame('Classes', $composer['autoload']['psr-4']['Acme\BudgetTracker\\']);
        self::assertStringContainsString("=> 'stable'", (string)file_get_contents($this->pathOf('budget_tracker', 'ext_emconf.php')));
        self::assertStringContainsString('Extension "budget_tracker" has been created.', $tester->getDisplay());
        self::assertStringContainsString('composer require acme/budget-tracker:@dev', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('invalidExtensionKeys')]
    public function rejectsAnInvalidExtensionKey(string $extensionKey): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, ['extensionKey' => $extensionKey]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'The extension key must be at least three characters long',
            $tester->getDisplay()
        );
        self::assertDirectoryDoesNotExist($this->pathOf($extensionKey, '.'));
    }

    #[Test]
    public function usesConfigurationDefaultsWithoutOptions(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, ['extensionKey' => 'budget_tracker']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $composer = json_decode((string)file_get_contents($this->pathOf('budget_tracker', 'composer.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('psbits/budget-tracker', $composer['name']);
        self::assertStringContainsString("=> 'Daniel Ablass'", (string)file_get_contents($this->pathOf('budget_tracker', 'ext_emconf.php')));
        self::assertStringContainsString("=> '0.1.0'", (string)file_get_contents($this->pathOf('budget_tracker', 'ext_emconf.php')));
    }

    #[Test]
    public function refusesToOverwriteAnExistingExtensionWithoutForce(): void
    {
        $target = $this->pathOf('budget_tracker', 'composer.json');
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, '{"name":"acme/budget-tracker"}');

        $tester = $this->runCommand($this->makeExtensionCommand, ['extensionKey' => 'budget_tracker']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame('{"name":"acme/budget-tracker"}', (string)file_get_contents($target));
    }

    #[Test]
    public function dryRunWritesNoFile(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, ['extensionKey' => 'budget_tracker', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->pathOf('budget_tracker', 'composer.json'));
    }

    #[Test]
    public function reportsUnknownFileGroupsAsAnError(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, [
            'extensionKey' => 'budget_tracker',
            '--groups'     => 'core,bogus',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown extension file group(s): bogus', $tester->getDisplay());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidExtensionKeys(): iterable
    {
        yield 'hyphen'      => ['my-ext'];
        yield 'leading digit' => ['1abc'];
        yield 'too short'   => ['ab'];
        yield 'uppercase'   => ['MyExt'];
    }
}
