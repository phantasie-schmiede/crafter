<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Utility\PhpUtility;

/**
 * Class ModuleRegistrar
 *
 * Adds a backend module of a generated module controller to the ExtensionInformation of the extension and makes
 * sure Configuration/Backend/Modules.php exists, because TYPO3 only picks up modules that are returned by that
 * file. Foundation builds the actual configuration from the ExtensionInformation, so the file itself stays a
 * one-liner that never has to be touched again.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ModuleRegistrar
{
    public const string MAIN_MODULE_CONFIGURATION = 'PSBits\Foundation\Data\MainModuleConfiguration';
    public const string MAIN_MODULE_NAME          = 'main';
    public const string MODULE_CONFIGURATION      = 'PSBits\Foundation\Data\ModuleConfiguration';
    public const string MODULE_SERVICE            = 'PSBits\Foundation\Service\Configuration\ModuleService';
    public const string SKELETON_MAIN_MODULE      = 'Php/ExtensionInformationMainModule';
    public const string SKELETON_MODULE           = 'Php/ExtensionInformationModule';
    public const string SKELETON_MODULES_FILE     = 'Extension/BackendModules';

    public function __construct(
        protected ClassGenerator       $classGenerator,
        protected CodeInjector         $codeInjector,
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    /**
     * Example: 'demo_shop', 'import' => 'demoshop_import'
     */
    public function buildModuleKey(string $extensionKey, string $moduleName): string
    {
        return strtolower(str_replace('_', '', $extensionKey)) . '_' . $moduleName;
    }

    /**
     * @return bool true when the module has been added, false when the file is missing or the module already exists
     */
    public function register(ClassBlueprint $blueprint, string $moduleName): bool
    {
        $extensionKey = $blueprint->extensionKey;
        $filePath     = $this->pathResolver->resolveExtensionPath($extensionKey)
            . 'Classes/Data/ExtensionInformation.php';

        if (false === is_file($filePath)) {
            return false;
        }

        $source = (string)file_get_contents($filePath);
        $this->ensureModuleConfiguration($extensionKey);

        // The main module has to exist before the module referencing it via $mainModuleKey.
        $modified = $this->codeInjector->addConstructorBlock(
            $source,
            rtrim($this->skeletonRenderer->render(
                self::SKELETON_MAIN_MODULE,
                ['mainModuleName' => self::MAIN_MODULE_NAME],
                $extensionKey
            )),
            CodeInjector::MAIN_MODULE_START,
            '',
            [
                self::MAIN_MODULE_CONFIGURATION,
                'TYPO3\CMS\Core\Utility\GeneralUtility',
            ],
            CodeInjector::PLUGIN_START
        );

        if (null !== $modified) {
            $source = $modified;
        }

        $modified = $this->codeInjector->addConstructorBlock(
            $source,
            rtrim($this->skeletonRenderer->render(
                self::SKELETON_MODULE,
                [
                    'controllerClass' => $blueprint->name,
                    'moduleName'      => $moduleName,
                ],
                $extensionKey
            )),
            CodeInjector::MODULE_START,
            $moduleName,
            [
                $this->classGenerator->getNamespace($blueprint) . '\\'
                    . $this->classGenerator->getClassName($blueprint),
                self::MODULE_CONFIGURATION,
                'TYPO3\CMS\Core\Utility\GeneralUtility',
            ],
            CodeInjector::PLUGIN_START
        );

        if (null === $modified) {
            if ($source === (string)file_get_contents($filePath)) {
                return false;
            }

            $modified = $source;
        }

        $this->fileWriter->modify($filePath, $modified);

        return true;
    }

    /**
     * Writes Configuration/Backend/Modules.php. Existing files are left untouched by the FileWriter, so a project
     * that has customised the file keeps its version.
     */
    private function ensureModuleConfiguration(string $extensionKey): void
    {
        $namespace = $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, 'PSBits')
        );
        $this->fileWriter->write(
            $this->pathResolver->resolveTargetPath('backendModules', $extensionKey),
            $this->skeletonRenderer->render(
                self::SKELETON_MODULES_FILE,
                [
                    'imports' => PhpUtility::renderImports([
                        $namespace . '\Data\ExtensionInformation',
                        self::MODULE_SERVICE,
                        'TYPO3\CMS\Core\Utility\GeneralUtility',
                    ]),
                ],
                $extensionKey
            )
        );
    }
}
