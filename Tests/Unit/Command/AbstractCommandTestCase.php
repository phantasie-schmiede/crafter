<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use PSBits\Crafter\Command\AuditCommand;
use PSBits\Crafter\Command\ConfigDumpCommand;
use PSBits\Crafter\Command\MakeBackendControllerCommand;
use PSBits\Crafter\Command\MakeControllerCommand;
use PSBits\Crafter\Command\MakeEventListenerCommand;
use PSBits\Crafter\Command\MakeExtensionCommand;
use PSBits\Crafter\Command\MakeModelCommand;
use PSBits\Crafter\Command\MakeRepositoryCommand;
use PSBits\Crafter\Command\MakeServiceCommand;
use PSBits\Crafter\Command\MakeViewHelperCommand;
use PSBits\Crafter\Command\SkeletonListCommand;
use PSBits\Crafter\Command\SkeletonPublishCommand;
use PSBits\Crafter\Command\SkeletonShowCommand;
use PSBits\Crafter\Command\SkeletonWhereCommand;
use PSBits\Crafter\Command\SyncCommand;
use PSBits\Crafter\Data\ControllerBlueprint;
use PSBits\Crafter\Data\ModelBlueprint;
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Service\ExtensionAuditor;
use PSBits\Crafter\Service\FieldTypeRegistry;
use PSBits\Crafter\Service\ModelPropertyReader;
use PSBits\Crafter\Tests\Unit\AbstractGeneratorTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AbstractCommandTestCase
 *
 * Runs the real commands against the real service graph of a throwaway project, non-interactively by default, so
 * the CLI contract - options, validation, orchestration and exit codes - is verified without a running TYPO3
 * installation.
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
abstract class AbstractCommandTestCase extends AbstractGeneratorTestCase
{
    protected AuditCommand                 $auditCommand;
    protected ConfigDumpCommand            $configDumpCommand;
    protected MakeBackendControllerCommand $makeBackendControllerCommand;
    protected MakeControllerCommand        $makeControllerCommand;
    protected MakeEventListenerCommand     $makeEventListenerCommand;
    protected MakeExtensionCommand         $makeExtensionCommand;
    protected MakeModelCommand             $makeModelCommand;
    protected MakeRepositoryCommand        $makeRepositoryCommand;
    protected MakeServiceCommand           $makeServiceCommand;
    protected MakeViewHelperCommand        $makeViewHelperCommand;
    protected SkeletonListCommand          $skeletonListCommand;
    protected SkeletonPublishCommand       $skeletonPublishCommand;
    protected SkeletonShowCommand          $skeletonShowCommand;
    protected SkeletonWhereCommand         $skeletonWhereCommand;
    protected SyncCommand                  $syncCommand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditCommand = new AuditCommand(
            new ExtensionAuditor(
                $this->configurationService,
                $this->extensionProfileService,
                $this->pathResolver,
                $this->syncService
            )
        );
        $this->configDumpCommand            = new ConfigDumpCommand($this->configurationService);
        $this->makeBackendControllerCommand = new MakeBackendControllerCommand(
            $this->backendControllerGenerator,
            $this->classGenerator,
            $this->fileWriter
        );
        $this->makeControllerCommand = new MakeControllerCommand(
            $this->controllerGenerator,
            $this->fileWriter,
            new ModelPropertyReader(new FieldTypeRegistry($this->configurationService)),
            $this->pathResolver
        );
        $this->makeEventListenerCommand = new MakeEventListenerCommand(
            $this->classGenerator,
            $this->fileWriter,
            $this->skeletonRenderer
        );
        $this->makeExtensionCommand = new MakeExtensionCommand(
            $this->configurationService,
            $this->extensionGenerator,
            $this->fileWriter,
            $this->pathResolver
        );
        $this->makeModelCommand = new MakeModelCommand(
            $this->controllerGenerator,
            new FieldTypeRegistry($this->configurationService),
            $this->fileWriter,
            $this->modelGenerator,
            $this->repositoryGenerator
        );
        $this->makeRepositoryCommand = new MakeRepositoryCommand(
            $this->fileWriter,
            $this->repositoryGenerator
        );
        $this->makeServiceCommand    = new MakeServiceCommand($this->classGenerator, $this->fileWriter);
        $this->makeViewHelperCommand = new MakeViewHelperCommand(
            $this->classGenerator,
            $this->fileWriter,
            $this->configurationService,
            $this->skeletonRenderer
        );
        $this->skeletonListCommand    = new SkeletonListCommand($this->pathResolver);
        $this->skeletonPublishCommand = new SkeletonPublishCommand($this->fileWriter, $this->pathResolver);
        $this->skeletonShowCommand    = new SkeletonShowCommand($this->skeletonRenderer);
        $this->skeletonWhereCommand   = new SkeletonWhereCommand($this->pathResolver);
        $this->syncCommand            = new SyncCommand($this->fileWriter, $this->syncService);
    }

    /**
     * Absolute path inside a generated extension under the throwaway project.
     */
    protected function pathOf(string $extensionKey, string $relativePath): string
    {
        return $this->projectPath . '/packages/' . $extensionKey . '/' . ltrim($relativePath, '/');
    }

    /**
     * Creates a hand written file in the generated extension, mirroring an extension a developer owns.
     */
    protected function createExtensionFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->getExtensionFilePath($relativePath);
        GeneralUtility::mkdir_deep(dirname($absolutePath));
        GeneralUtility::writeFile($absolutePath, $content, true);
    }

    /**
     * Creates an extension that satisfies every audit category: base files, a model, its repository, a controller
     * including templates, language files and a registered plugin, plus the synced toolchain.
     */
    protected function generateCompleteExtension(): void
    {
        $this->generateBaseExtension();
        $properties = [new PropertyDefinition(name: 'title', type: 'string', required: true)];
        $this->modelGenerator->generate(new ModelBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            properties: $properties
        ));
        $this->repositoryGenerator->generate(self::EXTENSION_KEY, 'Product');
        $this->controllerGenerator->generate(new ControllerBlueprint(
            extensionKey: self::EXTENSION_KEY,
            name: 'Product',
            modelName: 'Product',
            actions: ['list', 'show', 'new', 'create', 'edit', 'update', 'delete'],
            properties: $properties
        ));
        $this->syncService->sync(self::EXTENSION_KEY);
    }

    /**
     * Executes a command non-interactively and returns the tester for exit code and output assertions.
     *
     * @param array<string, mixed> $input
     */
    protected function runCommand(Command $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
