<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\ControllerBlueprint;

/**
 * Class PluginRegistrar
 *
 * Adds the plugin of a generated controller to the ExtensionInformation of the extension. Foundation derives the
 * whole plugin configuration from there and from the PluginAction attributes, so no further registration is needed.
 *
 * @package PSBits\Crafter\Service
 */
readonly class PluginRegistrar
{
    public const string CONTROLLER_SUB_NAMESPACE = 'Controller';
    public const string PLUGIN_CONFIGURATION     = 'PSBits\Foundation\Data\PluginConfiguration';
    public const string SKELETON_PLUGIN          = 'Php/ExtensionInformationPlugin';

    public function __construct(
        protected CodeInjector         $codeInjector,
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    /**
     * @return bool true when the plugin has been added, false when the file is missing or the plugin already exists
     */
    public function register(ControllerBlueprint $blueprint): bool
    {
        $extensionKey = $blueprint->extensionKey;
        $filePath     = $this->pathResolver->resolveExtensionPath($extensionKey)
            . 'Classes/Data/ExtensionInformation.php';

        if (false === is_file($filePath)) {
            return false;
        }

        $namespace = $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, 'PSBits')
        );
        $modified = $this->codeInjector->addConstructorBlock(
            (string)file_get_contents($filePath),
            rtrim($this->skeletonRenderer->render(
                self::SKELETON_PLUGIN,
                [
                    'controllerClass' => $blueprint->name,
                    'pluginName'      => $blueprint->getPluginName(),
                ],
                $extensionKey
            )),
            CodeInjector::PLUGIN_START,
            $blueprint->getPluginName(),
            [
                $namespace . '\\' . self::CONTROLLER_SUB_NAMESPACE . '\\' . $blueprint->name . 'Controller',
                self::PLUGIN_CONFIGURATION,
                'TYPO3\CMS\Core\Utility\GeneralUtility',
            ]
        );

        if (null === $modified) {
            return false;
        }

        $this->fileWriter->modify($filePath, $modified);

        return true;
    }
}
