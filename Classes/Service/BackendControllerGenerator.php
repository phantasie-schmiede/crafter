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
 * Class BackendControllerGenerator
 *
 * Assembles a backend module controller together with its Fluid templates, its language files and the module
 * registration inside the ExtensionInformation of the extension.
 *
 * @package PSBits\Crafter\Service
 */
readonly class BackendControllerGenerator
{
    public const string CLASS_TYPE              = 'backendController';
    public const string MODULE_ACTION_ATTRIBUTE = 'PSBits\Foundation\Attribute\ModuleAction';
    public const string MODULE_TEMPLATE_FACTORY = 'TYPO3\CMS\Backend\Template\ModuleTemplateFactory';
    public const string RESPONSE_INTERFACE      = 'Psr\Http\Message\ResponseInterface';
    public const string SKELETON_ACTION         = 'Php/ModuleAction';
    public const string SKELETON_CONSTRUCTOR    = 'Php/ParentConstructor';
    public const string SKELETON_TEMPLATE       = 'Fluid/Template/BackendModule';

    public function __construct(
        protected ClassGenerator        $classGenerator,
        protected ConfigurationService  $configurationService,
        protected FileWriter            $fileWriter,
        protected LanguageFileGenerator $languageFileGenerator,
        protected ModuleRegistrar       $moduleRegistrar,
        protected PathResolver          $pathResolver,
        protected SkeletonRenderer      $skeletonRenderer,
    ) {
    }

    /**
     * @param string[] $actions      module actions, the first one becomes the default action
     * @param string[] $dependencies fully qualified class names injected via the constructor
     */
    public function generate(
        string $extensionKey,
        string $name,
        array  $actions,
        array  $dependencies = [],
        string $moduleName = '',
        bool   $withTemplates = true,
        bool   $withLanguageFiles = true,
        bool   $withModule = true,
    ): void {
        $blueprint = $this->buildBlueprint($extensionKey, $name, $actions, $dependencies);
        $this->classGenerator->generate($blueprint);
        $labelIds = [];

        if (true === $withTemplates) {
            $labelIds = $this->generateTemplates($extensionKey, $name, $actions);
        }

        if (true === $withLanguageFiles) {
            $this->generateLanguageFiles($extensionKey, $name, $actions, $labelIds);
        }

        if (true === $withModule) {
            $this->moduleRegistrar->register(
                $blueprint,
                '' === $moduleName ? lcfirst($name) : $moduleName
            );

            if (true === $withLanguageFiles) {
                $this->generateModuleLanguageFiles($extensionKey, $name, $moduleName);
            }
        }
    }

    /**
     * @param string[] $actions
     * @param string[] $dependencies
     */
    public function buildBlueprint(
        string $extensionKey,
        string $name,
        array  $actions,
        array  $dependencies = [],
    ): ClassBlueprint {
        $defaultAction = (string)reset($actions);
        sort($actions);
        $methods = [$this->renderConstructor($extensionKey, $dependencies)];

        foreach ($actions as $action) {
            $methods[] = $this->renderAction($extensionKey, $action, $action === $defaultAction);
        }

        return new ClassBlueprint(
            extensionKey: $extensionKey,
            name        : $name,
            type        : self::CLASS_TYPE,
            imports     : array_merge($dependencies, [
                self::MODULE_ACTION_ATTRIBUTE,
                self::MODULE_TEMPLATE_FACTORY,
                self::RESPONSE_INTERFACE,
            ]),
            methods     : $methods
        );
    }

    /**
     * @param string[]                $actions
     * @param array<string, string[]> $labelIds label identifiers per action, derived from the rendered templates
     */
    private function generateLanguageFiles(
        string $extensionKey,
        string $name,
        array  $actions,
        array  $labelIds,
    ): void {
        foreach ($actions as $action) {
            $units = [];

            foreach ($labelIds[$action] ?? ['header'] as $id) {
                $units[$id] = 'header' === $id
                    ? $this->languageFileGenerator->humanize($action)
                    : $this->languageFileGenerator->humanize($id);
            }

            ksort($units);
            $this->languageFileGenerator->writeLanguageFiles(
                $extensionKey,
                'languageBackend',
                [
                    'name' => $action,
                    'path' => $name,
                ],
                'name',
                $units
            );
        }
    }

    private function generateModuleLanguageFiles(string $extensionKey, string $name, string $moduleName): void
    {
        $moduleName = '' === $moduleName ? lcfirst($name) : $moduleName;
        $labels     = (array)$this->configurationService->getValue('moduleLabels', $extensionKey, []);
        $units      = [];

        foreach ($labels as $id => $source) {
            $units[(string)$id] = str_replace(
                '{moduleTitle}',
                $this->languageFileGenerator->humanize($name),
                (string)$source
            );
        }

        if ([] === $units) {
            return;
        }

        ksort($units);
        $this->languageFileGenerator->writeLanguageFiles(
            $extensionKey,
            'languageBackend',
            [
                'name' => $this->moduleRegistrar->buildModuleKey($extensionKey, $moduleName),
                'path' => 'Modules',
            ],
            'name',
            $units
        );
    }

    /**
     * @param string[] $actions
     *
     * @return array<string, string[]> label identifiers per action
     */
    private function generateTemplates(string $extensionKey, string $name, array $actions): array
    {
        $labelIds = [];

        foreach ($actions as $action) {
            $content = $this->skeletonRenderer->render(
                self::SKELETON_TEMPLATE,
                [
                    'actionName'     => $action,
                    'controllerName' => $name,
                ],
                $extensionKey
            );
            $this->fileWriter->write(
                $this->pathResolver->resolveTargetPath('backendTemplate', $extensionKey, [
                    'action'     => ucfirst($action),
                    'controller' => $name,
                ]),
                $content
            );
            $labelIds[$action] = ControllerGenerator::extractLabelIds($content);
        }

        return $labelIds;
    }

    private function renderAction(string $extensionKey, string $action, bool $isDefault): string
    {
        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_ACTION,
            [
                'actionName'       => $action,
                'methodAttributes' => true === $isDefault ? '#[ModuleAction(default: true)]' : '#[ModuleAction]',
                'methodBody'       => 'return $this->htmlResponse();',
            ],
            $extensionKey
        ));
    }

    /**
     * The parent controller of Foundation expects the ModuleTemplateFactory, which therefore is passed on instead of
     * being promoted into a property.
     *
     * @param string[] $dependencies
     */
    private function renderConstructor(string $extensionKey, array $dependencies): string
    {
        $arguments   = $this->classGenerator->buildConstructorArguments($dependencies);
        $arguments[] = 'ModuleTemplateFactory $moduleTemplateFactory,';

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_CONSTRUCTOR,
            [
                'constructorArguments' => implode("\n", PhpUtility::alignArguments($arguments)),
                'parentArguments'      => '$moduleTemplateFactory',
            ],
            $extensionKey
        ));
    }
}
