<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Service;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use RuntimeException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_keys;
use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function str_replace;

/**
 * Exercises the renewal policies of crafter:sync against hand written extensions, so every policy is verified in
 * isolation: merge keeps user owned entries, createIfMissing preserves user edits and overwrite restores the
 * skeleton output.
 */
final class SyncServiceTest extends AbstractCrafterTestCase
{
    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function createIfMissingPreservesUserEdits(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');
        $this->createExtensionFile('AGENTS.md', "# Team\nHand written notes.");

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertSame(
            "# Team\nHand written notes.",
            (string)file_get_contents($this->getExtensionFilePath('AGENTS.md'))
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function mergeSyncIgnoresFormattingDrift(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile(
            'composer.json',
            '{"name":"acme/demo-shop","license":"proprietary"}'
        );

        $this->syncService->sync(self::EXTENSION_KEY);
        $this->syncService->sync(self::EXTENSION_KEY);
        $canonical  = (string)file_get_contents($this->getExtensionFilePath('composer.json'));
        $reindented = str_replace('    ', '  ', $canonical);
        GeneralUtility::writeFile($this->getExtensionFilePath('composer.json'), $reindented, true);

        $results = $this->syncService->sync(self::EXTENSION_KEY);

        self::assertSame([], $results);
        self::assertSame($reindented, (string)file_get_contents($this->getExtensionFilePath('composer.json')));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function missingPackageJsonGetsTheCompleteSkeleton(): void
    {
        $this->createExtensionFile('Resources/Private/Templates/Product/List.html', '<!-- product -->');
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');

        $this->syncService->sync(self::EXTENSION_KEY);

        $packageJsonPath = $this->getExtensionFilePath('package.json');
        self::assertFileExists($packageJsonPath);
        $packageJson            = json_decode((string)file_get_contents($packageJsonPath), true);
        $packageScripts         = $packageJson['scripts'];
        $packageDevDependencies = $packageJson['devDependencies'];

        self::assertSame('demo-shop', $packageJson['name']);
        self::assertSame('>=24', $packageJson['engines']['node']);
        self::assertSame(['fix:classes', 'fix:htmlxml', 'fix:json', 'lint:classes', 'lint:htmlxml', 'lint:json'], array_keys($packageScripts));
        self::assertArrayHasKey('prettier', $packageDevDependencies);
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function overwriteRestoresADriftedQualityConfig(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');
        $this->createExtensionFile('Build/Quality/.php-cs-fixer.dist.php', '// drifted');

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn PSBits\Qa\Quality\CsFixerFactory::create([\n    'Classes',\n    'Configuration',\n]);\n",
            (string)file_get_contents($this->getExtensionFilePath('Build/Quality/.php-cs-fixer.dist.php'))
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function syncCreatesTheClassAttributeLinterForHtmlContent(): void
    {
        $this->createExtensionFile('Resources/Private/Templates/Product/List.html', '<!-- product -->');
        $this->createExtensionFile(
            'composer.json',
            '{"name":"acme/demo-shop","description":"Demo shop","license":"proprietary"}'
        );

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertFileExists($this->getExtensionFilePath('Build/Quality/.prettierignore'));
        self::assertStringContainsString(
            '- `lint:classes`',
            (string)file_get_contents($this->getExtensionFilePath('AGENTS.md'))
        );
        $composer = $this->readComposer();
        self::assertSame(['npm install', '.Build/bin/qa sync'], $composer['scripts']['post-install-cmd']);
        $packageJson = json_decode((string)file_get_contents($this->getExtensionFilePath('package.json')), true);
        self::assertSame(
            'node .Build/vendor/psbits/qa/Resources/Quality/lint-class-attributes.mjs --dry-run',
            $packageJson['scripts']['lint:classes']
        );
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function syncCreatesTheMissingToolchainForPhpContent(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile(
            'composer.json',
            '{"name":"acme/demo-shop","description":"Demo shop","license":"proprietary",
                "require":{"php":">=8.3"},
                "scripts":{"test:user":"phpunit"}}'
        );

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertFileExists($this->getExtensionFilePath('AGENTS.md'));
        self::assertFileExists($this->getExtensionFilePath('CLAUDE.md'));
        self::assertStringContainsString(
            '- `lint:php`',
            (string)file_get_contents($this->getExtensionFilePath('AGENTS.md'))
        );
        self::assertFileExists($this->getExtensionFilePath('Build/Quality/phpstan.neon.dist'));
        self::assertFileExists($this->getExtensionFilePath('Build/Quality/.php-cs-fixer.dist.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/php-cs-fixer/ClassBlockSeparationFixer.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/php-cs-fixer/PropertyNamesAlignmentFixer.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/lint-class-attributes.mjs'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/GitHooks/pre-commit'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/.yamllint.yml'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/prettier.config.mjs'));

        $composer = $this->readComposer();
        self::assertSame('phpunit', $composer['scripts']['test:user']);
        self::assertSame(['.Build/bin/qa sync'], $composer['scripts']['post-install-cmd']);
        self::assertSame(['.Build/bin/qa sync'], $composer['scripts']['post-update-cmd']);
        self::assertArrayNotHasKey('lint:all', $composer['scripts']);
        self::assertArrayNotHasKey('fix:all', $composer['scripts']);
        self::assertArrayNotHasKey('qa', $composer['scripts']);
        self::assertSame('dev-latest', $composer['require-dev']['roave/security-advisories']);
        self::assertSame('^2.56', $composer['require-dev']['ergebnis/composer-normalize']);
    }

    /**
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function syncMergesAndReplacesManagedComposerKeys(): void
    {
        $this->createExtensionFile('Classes/Domain/Model/Product.php', "<?php\n");
        $this->createExtensionFile(
            'composer.json',
            '{"name":"acme/demo-shop","license":"proprietary","scripts":{
                "lint:all":"echo stale",
                "lint:php":"echo stale",
                "test:user":"phpunit"
            },"require-dev":{"foo/bar":"^2.0"}}'
        );

        $this->syncService->sync(self::EXTENSION_KEY);

        $composer = $this->readComposer();
        self::assertSame('echo stale', $composer['scripts']['lint:all']);
        self::assertSame('echo stale', $composer['scripts']['lint:php']);
        self::assertSame(['.Build/bin/qa sync'], $composer['scripts']['post-install-cmd']);
        self::assertSame(['.Build/bin/qa sync'], $composer['scripts']['post-update-cmd']);
        self::assertSame('phpunit', $composer['scripts']['test:user']);
        self::assertSame('^2.0', $composer['require-dev']['foo/bar']);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function syncMergesAndReplacesManagedPackageJsonKeys(): void
    {
        $this->createExtensionFile('Resources/Private/Templates/Product/List.html', '<!-- product -->');
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');
        $this->createExtensionFile(
            'package.json',
            '{
                "name": "demo-shop",
                "scripts": {
                    "lint:htmlxml": "echo stale",
                    "lint:json": "echo stale",
                    "test:user": "vitest"
                },
                "devDependencies": {
                    "prettier": "^2.0.0",
                    "vitest": "^3.0.0"
                }
            }'
        );

        $this->syncService->sync(self::EXTENSION_KEY);

        $packageJsonPath = $this->getExtensionFilePath('package.json');
        $packageJson     = json_decode((string)file_get_contents($packageJsonPath), true);
        self::assertSame('demo-shop', $packageJson['name']);
        self::assertSame('vitest', $packageJson['scripts']['test:user']);
        self::assertSame(
            'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs'
            . ' --ignore-path Build/Quality/.prettierignore --check "**/*.{html,xml,xlf}"',
            $packageJson['scripts']['lint:htmlxml']
        );
        self::assertSame(
            'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs'
            . ' --ignore-path Build/Quality/.prettierignore --check "package.json"',
            $packageJson['scripts']['lint:json']
        );
        self::assertSame(
            ['fix:classes', 'fix:htmlxml', 'fix:json', 'lint:classes', 'lint:htmlxml', 'lint:json', 'test:user'],
            array_keys($packageJson['scripts'])
        );
        self::assertSame('^3.6.2', $packageJson['devDependencies']['prettier']);
        self::assertSame('^3.0.0', $packageJson['devDependencies']['vitest']);
        self::assertArrayHasKey('@prettier/plugin-xml', $packageJson['devDependencies']);
        self::assertArrayHasKey('prettier-plugin-organize-attributes', $packageJson['devDependencies']);
        self::assertSame(
            str_replace(
                '    ',
                '  ',
                (string)json_encode(
                    $packageJson,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ) . "\n",
            (string)file_get_contents($packageJsonPath)
        );
    }

    /**
     * @throws JsonException
     * @throws RuntimeException
     */
    #[Test]
    public function syncThrowsWithoutAnExtensionDirectory(): void
    {
        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426450);

        $this->syncService->sync(self::EXTENSION_KEY);
    }

    /**
     * @throws RuntimeException
     */
    private function createExtensionFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->getExtensionFilePath($relativePath);
        GeneralUtility::mkdir_deep(dirname($absolutePath));
        GeneralUtility::writeFile($absolutePath, $content, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(): array
    {
        $composer = json_decode(
            (string)file_get_contents($this->getExtensionFilePath('composer.json')),
            true
        );

        return is_array($composer) ? $composer : [];
    }
}
