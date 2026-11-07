<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;

final class MakeExtensionCommandTest extends AbstractCommandTestCase
{
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

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function createsAnExtensionFromExplicitOptions(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, [
            '--author'          => 'Jane Doe',
            '--author-email'    => 'jane@example.com',
            '--category'        => 'plugin',
            '--composer-name'   => 'acme/budget-tracker',
            '--description'     => 'Tracks budgets',
            '--groups'          => 'core,quality',
            '--license'         => 'MIT',
            '--package-version' => '1.2.3',
            '--state'           => 'stable',
            '--title'           => 'Budget Tracker',
            '--vendor'          => 'Acme',
            'extensionKey'      => 'budget_tracker',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->pathOf('budget_tracker', 'composer.json'));
        self::assertFileExists($this->pathOf('budget_tracker', 'ext_emconf.php'));
        self::assertFileExists($this->pathOf('budget_tracker', 'Build/Quality/.php-cs-fixer.dist.php'));
        $composer = json_decode((string)file_get_contents($this->pathOf('budget_tracker', 'composer.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('acme/budget-tracker', $composer['name']);
        self::assertSame('Classes', $composer['autoload']['psr-4']['Acme\BudgetTracker\\']);
        self::assertStringContainsString("=> 'stable'", (string)file_get_contents($this->pathOf('budget_tracker', 'ext_emconf.php')));
        self::assertStringContainsString('Extension "budget_tracker" has been created.', $tester->getDisplay());
        self::assertStringContainsString('composer require acme/budget-tracker:@dev', $tester->getDisplay());
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function dryRunWritesNoFile(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, ['--dry-run' => true, 'extensionKey' => 'budget_tracker']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run - no file has been written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->pathOf('budget_tracker', 'composer.json'));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    #[Test]
    public function reportsUnknownFileGroupsAsAnError(): void
    {
        $tester = $this->runCommand($this->makeExtensionCommand, [
            '--groups'     => 'core,bogus',
            'extensionKey' => 'budget_tracker',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown extension file group(s): bogus', $tester->getDisplay());
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
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
}
