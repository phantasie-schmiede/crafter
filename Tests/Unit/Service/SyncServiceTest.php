<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Tests\Unit\AbstractCrafterTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function file_get_contents;
use function json_decode;
use function str_replace;

/**
 * Class SyncServiceTest
 *
 * Exercises the renewal policies of crafter:sync against hand written extensions, so every policy is verified in
 * isolation: merge keeps user owned entries, createIfMissing preserves user edits and overwrite restores the
 * skeleton output.
 *
 * @package PSBits\Crafter\Tests\Unit\Service
 */
final class SyncServiceTest extends AbstractCrafterTestCase
{
    #[Test]
    public function syncThrowsWithoutAnExtensionDirectory(): void
    {
        $this->expectException(CrafterException::class);
        $this->expectExceptionCode(1757426450);

        $this->syncService->sync(self::EXTENSION_KEY);
    }

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
        self::assertFileExists($this->getExtensionFilePath('Build/Quality/php-cs-fixer/ClassBlockSeparationFixer.php'));
        self::assertFileExists($this->getExtensionFilePath('Build/Quality/php-cs-fixer/PropertyNamesAlignmentFixer.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/lint-class-attributes.mjs'));
        self::assertFileExists($this->getExtensionFilePath('Build/GitHooks/pre-commit'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/.yamllint.yml'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Build/Quality/prettier.config.mjs'));

        $composer = $this->readComposer();
        self::assertSame('phpunit', $composer['scripts']['test:user']);
        self::assertSame(
            ['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'],
            $composer['scripts']['lint:all']
        );
        self::assertSame('dev-latest', $composer['require-dev']['roave/security-advisories']);
    }

    #[Test]
    public function syncCreatesTheClassAttributeLinterForHtmlContent(): void
    {
        $this->createExtensionFile('Resources/Private/Templates/Product/List.html', '<!-- product -->');
        $this->createExtensionFile(
            'composer.json',
            '{"name":"acme/demo-shop","description":"Demo shop","license":"proprietary"}'
        );

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertFileExists($this->getExtensionFilePath('Build/Quality/lint-class-attributes.mjs'));
        self::assertStringContainsString(
            '- `lint:classes`',
            (string)file_get_contents($this->getExtensionFilePath('AGENTS.md'))
        );
        $composer = $this->readComposer();
        self::assertSame('npm run lint:classes', $composer['scripts']['lint:classes']);
        self::assertContains('@lint:classes', $composer['scripts']['lint:all']);
    }

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
        self::assertSame(['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'], $composer['scripts']['lint:all']);
        self::assertSame('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', $composer['scripts']['lint:php']);
        self::assertSame('phpunit', $composer['scripts']['test:user']);
        self::assertSame('^2.0', $composer['require-dev']['foo/bar']);
    }

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

    #[Test]
    public function overwriteRestoresADriftedQualityConfig(): void
    {
        $this->createExtensionFile('Configuration/Settings.yaml', "foo: bar\n");
        $this->createExtensionFile('composer.json', '{"name":"acme/demo-shop","license":"proprietary"}');
        $this->createExtensionFile('Build/Quality/.yamllint.yml', '# broken');

        $this->syncService->sync(self::EXTENSION_KEY);

        self::assertSame(
            "extends: default\n\nrules:\n  line-length:\n    max: 140\n    level: warning\n  comments: disable\n  document-start: disable\n  truthy: disable\n",
            (string)file_get_contents($this->getExtensionFilePath('Build/Quality/.yamllint.yml'))
        );
    }

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
        self::assertSame(['fix:classes', 'fix:htmlxml', 'lint:classes', 'lint:htmlxml'], array_keys($packageScripts));
        self::assertArrayHasKey('prettier', $packageDevDependencies);
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

    private function createExtensionFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->getExtensionFilePath($relativePath);
        GeneralUtility::mkdir_deep(dirname($absolutePath));
        GeneralUtility::writeFile($absolutePath, $content, true);
    }
}
