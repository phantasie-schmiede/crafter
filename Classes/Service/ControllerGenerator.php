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
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Utility\PhpUtility;

use function array_key_exists;
use function is_array;
use function sprintf;

/**
 * Class ControllerGenerator
 *
 * Assembles a frontend controller together with its Fluid templates, form fields partial and language files.
 * Which actions exist and what they do is defined in crafter.controllerActions, so the whole behaviour can be
 * changed without touching this class.
 *
 * @package PSBits\Crafter\Service
 */
readonly class ControllerGenerator
{
    public const string CONTROLLER_SUB_NAMESPACE = 'Controller';
    public const string MODEL_SUB_NAMESPACE      = 'Domain\Model';
    public const string PLUGIN_ACTION_ATTRIBUTE  = 'PSBits\Foundation\Attribute\PluginAction';
    public const string REPOSITORY_SUB_NAMESPACE = 'Domain\Repository';
    public const string RESPONSE_INTERFACE       = 'Psr\Http\Message\ResponseInterface';
    public const string SKELETON_ACTION          = 'Php/ControllerAction';
    public const string SKELETON_CONSTRUCTOR     = 'Php/Constructor';
    public const string SKELETON_CONTROLLER      = 'Php/Controller';
    public const string SKELETON_FORM_ELEMENT    = 'Fluid/FormElement';
    public const string SKELETON_FORM_FIELD      = 'Fluid/FormField';
    public const string SKELETON_FORM_PARTIAL    = 'Fluid/FormFieldsPartial';
    public const string SKELETON_LIST_NEW_LINK   = 'Fluid/Template/ListNewLink';
    public const string SKELETON_SHOW_DELETE     = 'Fluid/Template/ShowDeleteForm';
    public const string SKELETON_SHOW_EDIT_LINK  = 'Fluid/Template/ShowEditLink';

    public function __construct(
        protected ConfigurationService  $configurationService,
        protected FileWriter            $fileWriter,
        protected LanguageFileGenerator $languageFileGenerator,
        protected PathResolver          $pathResolver,
        protected PluginRegistrar       $pluginRegistrar,
        protected SkeletonRenderer      $skeletonRenderer,
    ) {
    }

    public function generate(ControllerBlueprint $blueprint): void
    {
        $extensionKey = $blueprint->extensionKey;
        $this->fileWriter->write(
            $this->pathResolver->resolveTargetPath('controller', $extensionKey, ['name' => $blueprint->name]),
            $this->render($blueprint)
        );
        $labelIds = [];

        if (true === $blueprint->withTemplates) {
            $labelIds   = $this->generateTemplates($blueprint);
            $partialIds = $this->generateFormFieldsPartial($blueprint);

            foreach (['edit', 'new'] as $formAction) {
                if (true === isset($labelIds[$formAction])) {
                    $labelIds[$formAction] = array_merge($labelIds[$formAction], $partialIds);
                }
            }
        }

        if (true === $blueprint->withLanguageFiles) {
            $this->languageFileGenerator->generateForController($blueprint, $labelIds);
        }

        if (true === $blueprint->withPlugin) {
            $this->pluginRegistrar->register($blueprint);
        }
    }

    /**
     * Collects the label identifiers a rendered Fluid file asks for, so the language files always match the
     * templates, even when those have been overridden.
     *
     * @return string[]
     */
    public static function extractLabelIds(string $content): array
    {
        preg_match_all(
            '/<foundation:translate\s+id="([^"]+)"|translate\(id:\s*\\\\?\'([^\'\\\\]+)/',
            $content,
            $matches
        );

        return array_values(array_unique(array_filter(array_merge($matches[1], $matches[2]))));
    }

    /**
     * @return array<string, mixed>
     */
    public function getActionConfiguration(string $action, string $extensionKey): array
    {
        $actions = $this->getAvailableActions($extensionKey);

        if (!isset($actions[$action])) {
            throw new CrafterException(
                sprintf(
                    'Unknown action "%s". Available actions: %s',
                    $action,
                    implode(', ', array_keys($actions))
                ),
                1757426415
            );
        }

        return (array)$actions[$action];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailableActions(?string $extensionKey = null): array
    {
        $actions = $this->configurationService->getValue('controllerActions', $extensionKey, []);

        return is_array($actions) ? $actions : [];
    }

    public function render(ControllerBlueprint $blueprint): string
    {
        $extensionKey        = $blueprint->extensionKey;
        $controllerNamespace = $this->getNamespace($extensionKey, self::CONTROLLER_SUB_NAMESPACE);
        $parentClassName     = (string)$this->configurationService->getValue(
            'defaults.controllerExtends',
            $extensionKey,
            'TYPO3\CMS\Extbase\Mvc\Controller\ActionController'
        );
        $constructorArguments = [];
        $imports              = [$parentClassName];
        $methods              = [];
        $bodies               = '';

        foreach ($this->sortActions($blueprint->actions, $extensionKey) as $action) {
            $configuration = $this->getActionConfiguration($action, $extensionKey);
            $body          = rtrim($this->skeletonRenderer->render(
                (string)($configuration['bodySkeleton'] ?? 'Php/Action/Default'),
                $this->buildVariables($blueprint),
                $extensionKey
            ));
            $bodies .= $body . "\n";
            $imports   = array_merge($imports, (array)($configuration['imports'] ?? []));
            $methods[] = $this->renderAction($action, $configuration, $blueprint, $body, $imports);
        }

        $constructorArguments = $this->getConstructorArguments($bodies, $blueprint, $imports);
        sort($constructorArguments);
        $sections = array_values(array_filter(array_merge(
            [$this->renderConstructor($constructorArguments, $extensionKey)],
            $methods
        )));

        return $this->skeletonRenderer->render(
            self::SKELETON_CONTROLLER,
            [
                'classBody'           => implode("\n\n", $sections),
                'className'           => $blueprint->name,
                'controllerNamespace' => $controllerNamespace,
                'imports'             => PhpUtility::renderImports($imports, $controllerNamespace),
                'parentClass'         => PhpUtility::getShortClassName($parentClassName),
            ],
            $extensionKey
        );
    }

    /**
     * Placeholders shared by all controller, template and language file skeletons.
     *
     * @return array<string, string>
     */
    public function buildVariables(ControllerBlueprint $blueprint): array
    {
        $modelVariable = $blueprint->getModelVariable();

        return [
            'controllerName'     => $blueprint->name,
            'labelExpression'    => $this->getLabelExpression($blueprint),
            'modelArguments'     => sprintf('{%s: %s}', $modelVariable, $modelVariable),
            'modelName'          => $blueprint->getModelName(),
            'modelObject'        => sprintf('{%s}', $modelVariable),
            'modelVariable'      => $modelVariable,
            'partialName'        => $blueprint->getModelName(),
            'pluralObject'       => sprintf('{%s}', $blueprint->getPluralVariable()),
            'pluralVariable'     => $blueprint->getPluralVariable(),
            'repositoryProperty' => $modelVariable . 'Repository',
        ];
    }

    /**
     * Blocks of the templates that only make sense when the matching action exists.
     *
     * @return array<string, string>
     */
    private function buildOptionalBlocks(ControllerBlueprint $blueprint): array
    {
        $extensionKey = $blueprint->extensionKey;
        $variables    = $this->buildVariables($blueprint);
        $blocks       = [
            'deleteForm' => ['delete', self::SKELETON_SHOW_DELETE],
            'editLink'   => ['edit', self::SKELETON_SHOW_EDIT_LINK],
            'newLink'    => ['new', self::SKELETON_LIST_NEW_LINK],
        ];
        $rendered = [];

        foreach ($blocks as $name => [$action, $skeleton]) {
            $rendered[$name] = false === $blueprint->hasAction($action)
                ? ''
                : rtrim($this->skeletonRenderer->render($skeleton, $variables, $extensionKey));
        }

        return $rendered;
    }

    /**
     * @return string[]
     */
    private function generateFormFieldsPartial(ControllerBlueprint $blueprint): array
    {
        if (false === $blueprint->hasAction('edit') && false === $blueprint->hasAction('new')) {
            return [];
        }

        $fields = $this->renderFormFields($blueprint);

        if ('' === $fields) {
            return [];
        }

        $content = $this->skeletonRenderer->render(
            self::SKELETON_FORM_PARTIAL,
            array_merge($this->buildVariables($blueprint), ['formFields' => $fields]),
            $blueprint->extensionKey
        );
        $this->fileWriter->write(
            $this->pathResolver->resolveTargetPath(
                'formFieldsPartial',
                $blueprint->extensionKey,
                ['name' => $blueprint->getModelName()]
            ),
            $content
        );

        return self::extractLabelIds($content);
    }

    /**
     * @return array<string, string[]> label identifiers per action
     */
    private function generateTemplates(ControllerBlueprint $blueprint): array
    {
        $extensionKey = $blueprint->extensionKey;
        $variables    = array_merge($this->buildVariables($blueprint), $this->buildOptionalBlocks($blueprint));
        $labelIds     = [];

        foreach ($blueprint->actions as $action) {
            $skeleton = (string)($this->getActionConfiguration($action, $extensionKey)['template'] ?? '');

            if ('' === $skeleton) {
                continue;
            }

            $content = $this->skeletonRenderer->render($skeleton, $variables, $extensionKey);
            $this->fileWriter->write(
                $this->pathResolver->resolveTargetPath('template', $extensionKey, [
                    'action'     => ucfirst($action),
                    'controller' => $blueprint->name,
                ]),
                $content
            );
            $labelIds[$action] = self::extractLabelIds($content);
        }

        return $labelIds;
    }

    /**
     * The constructor is derived from what the rendered action bodies actually use, so overridden body skeletons
     * automatically get the dependencies they need.
     *
     * @param string[] $imports
     *
     * @return string[]
     */
    private function getConstructorArguments(
        string              $bodies,
        ControllerBlueprint $blueprint,
        array               &$imports,
    ): array {
        $arguments          = [];
        $repositoryProperty = $blueprint->getModelVariable() . 'Repository';

        if (true === str_contains($bodies, '$this->' . $repositoryProperty)) {
            $imports[] = $this->getNamespace($blueprint->extensionKey, self::REPOSITORY_SUB_NAMESPACE)
                . '\\' . $blueprint->getModelName() . 'Repository';
            $arguments[] = sprintf(
                'protected readonly %sRepository $%s,',
                $blueprint->getModelName(),
                $repositoryProperty
            );
        }

        if (true === str_contains($bodies, '$this->persistenceManager')) {
            $imports[]   = 'TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager';
            $arguments[] = 'protected readonly PersistenceManager $persistenceManager,';
        }

        return $arguments;
    }

    private function getLabelExpression(ControllerBlueprint $blueprint): string
    {
        $labelProperty = $blueprint->getLabelProperty();

        if ('' === $labelProperty) {
            return sprintf('{%s.uid}', $blueprint->getModelVariable());
        }

        return sprintf('{%s.%s}', $blueprint->getModelVariable(), $labelProperty);
    }

    private function getNamespace(string $extensionKey, string $subNamespace): string
    {
        return $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, 'PSBits')
        ) . '\\' . $subNamespace;
    }

    /**
     * @param array<string, mixed> $configuration
     * @param string[]             $imports
     */
    private function renderAction(
        string              $action,
        array               $configuration,
        ControllerBlueprint $blueprint,
        string              $body,
        array               &$imports,
    ): string {
        $variables  = $this->buildVariables($blueprint);
        $returnType = (string)($configuration['returnType'] ?? 'ResponseInterface');

        if ('ResponseInterface' === $returnType) {
            $imports[] = self::RESPONSE_INTERFACE;
        }

        $attributes = (array)($configuration['attributes'] ?? []);

        if ([] !== $attributes) {
            $imports[] = self::PLUGIN_ACTION_ATTRIBUTE;
        }

        $arguments = '';

        if (true === (bool)($configuration['modelArgument'] ?? false)) {
            $imports[] = $this->getNamespace($blueprint->extensionKey, self::MODEL_SUB_NAMESPACE)
                . '\\' . $blueprint->getModelName();
            $arguments = sprintf('%s $%s', $blueprint->getModelName(), $blueprint->getModelVariable());
        }

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_ACTION,
            array_merge($variables, [
                'actionName'       => $action,
                'methodArguments'  => $arguments,
                'methodAttributes' => implode("\n", array_map(
                    static fn(mixed $attribute): string => '#[' . (string)$attribute . ']',
                    $attributes
                )),
                'methodBody'       => $body,
                'methodDocBlock'   => $this->renderThrowsDocBlock((array)($configuration['throws'] ?? [])),
                'methodReturnType' => $returnType,
            ]),
            $blueprint->extensionKey
        ));
    }

    /**
     * @param string[] $arguments
     */
    private function renderConstructor(array $arguments, string $extensionKey): string
    {
        if ([] === $arguments) {
            return '';
        }

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_CONSTRUCTOR,
            ['constructorArguments' => implode("\n", PhpUtility::alignArguments($arguments))],
            $extensionKey
        ));
    }

    private function renderFormFields(ControllerBlueprint $blueprint): string
    {
        $extensionKey = $blueprint->extensionKey;
        $formFields   = $this->configurationService->getValue('formFields', $extensionKey, []);
        $fields       = [];

        if (!is_array($formFields)) {
            return '';
        }

        foreach ($blueprint->properties as $property) {
            // A field type explicitly set to ~ has no form field and must not fall back to the default.
            $configuration = array_key_exists($property->type, $formFields)
                ? $formFields[$property->type]
                : ($formFields['default'] ?? null);

            if (!is_array($configuration)) {
                continue;
            }

            $fieldId    = sprintf('%s-%s', $blueprint->getModelVariable(), $property->name);
            $attributes = array_merge(
                (array)($configuration['attributes'] ?? []),
                [
                    'id'       => $fieldId,
                    'property' => $property->name,
                ]
            );
            ksort($attributes);
            $fields[] = rtrim($this->skeletonRenderer->render(
                self::SKELETON_FORM_FIELD,
                [
                    'fieldId'      => $fieldId,
                    'formElement'  => rtrim($this->skeletonRenderer->render(
                        self::SKELETON_FORM_ELEMENT,
                        [
                            'fieldAttributes' => $this->renderFieldAttributes($attributes),
                            'fieldViewHelper' => (string)($configuration['viewHelper'] ?? 'f:form.textfield'),
                        ],
                        $extensionKey
                    )),
                    'propertyName' => $property->name,
                ],
                $extensionKey
            ));
        }

        return implode("\n\n", $fields);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function renderFieldAttributes(array $attributes): string
    {
        $rendered = [];

        foreach ($attributes as $name => $value) {
            $rendered[] = sprintf('%s="%s"', $name, (string)$value);
        }

        return implode("\n    ", $rendered);
    }

    /**
     * @param array<int, mixed> $throws
     */
    private function renderThrowsDocBlock(array $throws): string
    {
        if ([] === $throws) {
            return '';
        }

        $lines = array_map(static fn(mixed $throw): string => ' * @throws ' . (string)$throw, $throws);
        sort($lines);

        return implode("\n", array_merge(['/**'], $lines, [' */']));
    }

    /**
     * Actions are written in the order of crafter.controllerActions, which keeps the methods alphabetical.
     *
     * @param string[] $actions
     *
     * @return string[]
     */
    private function sortActions(array $actions, string $extensionKey): array
    {
        $available = array_keys($this->getAvailableActions($extensionKey));
        $sorted    = array_values(array_intersect($available, $actions));
        $unknown   = array_diff($actions, $available);

        if ([] !== $unknown) {
            // Let getActionConfiguration() throw with the list of available actions.
            $this->getActionConfiguration((string)reset($unknown), $extensionKey);
        }

        return $sorted;
    }
}
