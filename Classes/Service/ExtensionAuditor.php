<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\AuditFinding;
use PSBits\Crafter\Enum\AuditSeverity;
use PSBits\Crafter\Exception\CrafterException;

/**
 * Class ExtensionAuditor
 *
 * Compares an existing extension against the conventions Crafter generates by. Every check derives its expected
 * paths from the very same `paths` configuration the generators use, so a project with an own directory layout is
 * audited against its own layout instead of the shipped defaults.
 *
 * The auditor never writes anything. Each finding carries the command that resolves it.
 *
 * @package PSBits\Crafter\Service
 */
class ExtensionAuditor
{
    public const string CATEGORY_CONTROLLER            = 'controller';
    public const string CATEGORY_LANGUAGE              = 'language';
    public const string CATEGORY_MODEL                 = 'model';
    public const string CATEGORY_ORPHAN                = 'orphan';
    public const string CATEGORY_REGISTRATION          = 'registration';
    public const string CATEGORY_STRUCTURE             = 'structure';
    public const string EXTENSION_INFORMATION_SKELETON = 'Extension/ExtensionInformation';

    public function __construct(
        protected readonly ConfigurationService $configurationService,
        protected readonly PathResolver         $pathResolver,
    ) {
    }

    /**
     * @param string[] $categories
     *
     * @return AuditFinding[]
     */
    public function audit(string $extensionKey, array $categories = []): array
    {
        $extensionPath = $this->pathResolver->resolveExtensionPath($extensionKey);

        if (!is_dir($extensionPath)) {
            throw new CrafterException(
                sprintf('Extension "%s" was not found at "%s".', $extensionKey, $extensionPath),
                1757426420
            );
        }

        $categories  = [] === $categories ? $this->getCategories($extensionKey) : $categories;
        $controllers = $this->collectControllers($extensionKey);
        $findings    = [];

        if (in_array(self::CATEGORY_STRUCTURE, $categories, true)) {
            array_push($findings, ...$this->auditStructure($extensionKey, $extensionPath));
        }

        if (in_array(self::CATEGORY_MODEL, $categories, true)) {
            array_push($findings, ...$this->auditModels($extensionKey, $extensionPath));
        }

        if (in_array(self::CATEGORY_CONTROLLER, $categories, true)) {
            array_push($findings, ...$this->auditControllers($extensionKey, $extensionPath, $controllers));
        }

        if (in_array(self::CATEGORY_LANGUAGE, $categories, true)) {
            array_push($findings, ...$this->auditLanguageFiles($extensionKey, $extensionPath, $controllers));
        }

        if (in_array(self::CATEGORY_REGISTRATION, $categories, true)) {
            array_push($findings, ...$this->auditRegistration($extensionKey, $extensionPath, $controllers));
        }

        if (in_array(self::CATEGORY_ORPHAN, $categories, true)) {
            array_push($findings, ...$this->auditOrphans($extensionKey, $extensionPath, $controllers));
        }

        usort(
            $findings,
            static fn(AuditFinding $a, AuditFinding $b): int => [
                $a->severity->getWeight(),
                $a->category,
                $a->path,
                $a->message,
            ] <=> [$b->severity->getWeight(), $b->category, $b->path, $b->message]
        );

        return $findings;
    }

    /**
     * @return string[]
     */
    public function getCategories(?string $extensionKey = null): array
    {
        return $this->getListValue('audit.categories', $extensionKey);
    }

    /**
     * @param array<string, array{actions: string[], backend: bool, name: string, path: string}> $controllers
     *
     * @return AuditFinding[]
     */
    private function auditControllers(string $extensionKey, string $extensionPath, array $controllers): array
    {
        $actionConfiguration = $this->configurationService->getValue('controllerActions', $extensionKey, []);
        $findings            = [];

        foreach ($controllers as $controller) {
            foreach ($controller['actions'] as $action) {
                $templatePath = $this->resolveTemplatePath($extensionKey, $controller, $action);

                if (is_file($templatePath)) {
                    continue;
                }

                $configuration = is_array($actionConfiguration) ? ($actionConfiguration[$action] ?? null) : null;

                // A configured action without a `template` key redirects instead of rendering, e.g. create or delete.
                if (false === $controller['backend'] && is_array($configuration)
                    && !array_key_exists('template', $configuration)) {
                    continue;
                }

                $findings[] = new AuditFinding(
                    category: self::CATEGORY_CONTROLLER,
                    severity: null === $configuration && false === $controller['backend']
                        ? AuditSeverity::notice
                        : AuditSeverity::warning,
                    message : sprintf(
                        'Action "%s" of %sController has no template.',
                        $action,
                        $controller['name']
                    ),
                    path    : $this->makeRelative($templatePath, $extensionPath)
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array{actions: string[], backend: bool, name: string, path: string}> $controllers
     *
     * @return AuditFinding[]
     */
    private function auditLanguageFiles(string $extensionKey, string $extensionPath, array $controllers): array
    {
        $findings  = [];
        $languages = $this->getListValue('audit.languages', $extensionKey);

        foreach ($controllers as $controller) {
            foreach ($controller['actions'] as $action) {
                $templatePath = $this->resolveTemplatePath($extensionKey, $controller, $action);

                if (!is_file($templatePath)) {
                    continue;
                }

                // The expected labels are read from the template itself, so overridden templates are respected.
                $labelIds = ControllerGenerator::extractLabelIds((string)file_get_contents($templatePath));

                if ([] === $labelIds) {
                    continue;
                }

                $languagePath = $this->resolveLanguagePath($extensionKey, $controller, $action);

                if (!is_file($languagePath)) {
                    $findings[] = new AuditFinding(
                        category: self::CATEGORY_LANGUAGE,
                        severity: AuditSeverity::error,
                        message : sprintf(
                            'Template of "%s" uses %d label(s) but there is no language file.',
                            $action,
                            count($labelIds)
                        ),
                        path    : $this->makeRelative($languagePath, $extensionPath)
                    );

                    continue;
                }

                array_push($findings, ...$this->compareLabels($labelIds, $languagePath, $extensionPath));
                array_push(
                    $findings,
                    ...$this->checkTranslations($languagePath, $extensionPath, $languages)
                );
            }
        }

        return $findings;
    }

    /**
     * @return AuditFinding[]
     */
    private function auditModels(string $extensionKey, string $extensionPath): array
    {
        $findings = [];

        foreach ($this->listFiles('model', $extensionKey, ['name' => '*']) as $modelPath) {
            $name           = basename($modelPath, '.php');
            $repositoryPath = $this->pathResolver->resolveTargetPath('repository', $extensionKey, ['name' => $name]);

            if (is_file($repositoryPath)) {
                continue;
            }

            $findings[] = new AuditFinding(
                category: self::CATEGORY_MODEL,
                severity: AuditSeverity::warning,
                message : sprintf('Model "%s" has no repository.', $name),
                path    : $this->makeRelative($repositoryPath, $extensionPath),
                hint    : sprintf('crafter:make:repository %s -e %s', $name, $extensionKey)
            );
        }

        return $findings;
    }

    /**
     * @param array<string, array{actions: string[], backend: bool, name: string, path: string}> $controllers
     *
     * @return AuditFinding[]
     */
    private function auditOrphans(string $extensionKey, string $extensionPath, array $controllers): array
    {
        $findings = [];

        foreach ($this->listFiles('repository', $extensionKey, ['name' => '*']) as $repositoryPath) {
            $name = preg_replace('/Repository$/', '', basename($repositoryPath, '.php')) ?? '';

            if (is_file($this->pathResolver->resolveTargetPath('model', $extensionKey, ['name' => $name]))) {
                continue;
            }

            $findings[] = new AuditFinding(
                category: self::CATEGORY_ORPHAN,
                severity: AuditSeverity::warning,
                message : sprintf('Repository "%sRepository" has no domain model.', $name),
                path    : $this->makeRelative($repositoryPath, $extensionPath)
            );
        }

        foreach ($controllers as $controller) {
            $expectedTemplates = array_map(ucfirst(...), $controller['actions']);
            $templateType      = true === $controller['backend'] ? 'backendTemplate' : 'template';

            foreach ($this->listFiles($templateType, $extensionKey, [
                'action'     => '*',
                'controller' => $controller['name'],
            ]) as $templatePath) {
                if (in_array(basename($templatePath, '.html'), $expectedTemplates, true)) {
                    continue;
                }

                $findings[] = new AuditFinding(
                    category: self::CATEGORY_ORPHAN,
                    severity: AuditSeverity::warning,
                    message : sprintf(
                        'Template has no matching action in %sController.',
                        $controller['name']
                    ),
                    path    : $this->makeRelative($templatePath, $extensionPath)
                );
            }

            foreach ($this->listLanguageFiles($extensionKey, $controller) as $languagePath) {
                $action = basename($languagePath, '.xlf');

                // Translations are prefixed with their language key and belong to the base file, not to an action.
                if (str_contains($action, '.')) {
                    continue;
                }

                if (is_file($this->resolveTemplatePath($extensionKey, $controller, $action))) {
                    continue;
                }

                $findings[] = new AuditFinding(
                    category: self::CATEGORY_ORPHAN,
                    severity: AuditSeverity::notice,
                    message : sprintf(
                        'Language file has no matching template in %sController.',
                        $controller['name']
                    ),
                    path    : $this->makeRelative($languagePath, $extensionPath)
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array{actions: string[], backend: bool, name: string, path: string}> $controllers
     *
     * @return AuditFinding[]
     */
    private function auditRegistration(string $extensionKey, string $extensionPath, array $controllers): array
    {
        $informationPath = $this->getExtensionInformationPath($extensionKey, $extensionPath);

        // A missing file is already reported by the structure category.
        if (!is_file($informationPath)) {
            return [];
        }

        $content  = (string)file_get_contents($informationPath);
        $findings = [];

        foreach ($controllers as $controller) {
            if (str_contains($content, $controller['name'] . 'Controller::class')) {
                continue;
            }

            $findings[] = new AuditFinding(
                category: self::CATEGORY_REGISTRATION,
                severity: AuditSeverity::warning,
                message : sprintf(
                    '%sController is neither registered as plugin nor as backend module.',
                    $controller['name']
                ),
                path    : $this->makeRelative($informationPath, $extensionPath),
                hint    : sprintf(
                    'crafter:make:%s %s -e %s --force',
                    true === $controller['backend'] ? 'backend-controller' : 'controller',
                    $controller['name'],
                    $extensionKey
                )
            );
        }

        return $findings;
    }

    /**
     * @return AuditFinding[]
     */
    private function auditStructure(string $extensionKey, string $extensionPath): array
    {
        $findings = [];

        foreach ($this->getListValue('audit.requiredFiles', $extensionKey) as $relativePath) {
            if (is_file($extensionPath . $relativePath)) {
                continue;
            }

            $findings[] = new AuditFinding(
                category: self::CATEGORY_STRUCTURE,
                severity: AuditSeverity::error,
                message : 'Required file is missing.',
                path    : $relativePath,
                hint    : sprintf('crafter:make:extension %s', $extensionKey)
            );
        }

        return $findings;
    }

    /**
     * @param string[] $languages
     *
     * @return AuditFinding[]
     */
    private function checkTranslations(string $languagePath, string $extensionPath, array $languages): array
    {
        $findings = [];

        foreach ($languages as $language) {
            $translationPath = dirname($languagePath) . '/' . $language . '.' . basename($languagePath);

            if (is_file($translationPath)) {
                continue;
            }

            $findings[] = new AuditFinding(
                category: self::CATEGORY_LANGUAGE,
                severity: AuditSeverity::notice,
                message : sprintf('Translation for "%s" is missing.', $language),
                path    : $this->makeRelative($translationPath, $extensionPath)
            );
        }

        return $findings;
    }

    /**
     * @return array<string, array{actions: string[], backend: bool, name: string, path: string}>
     */
    private function collectControllers(string $extensionKey): array
    {
        $controllers = [];

        foreach (['backendController' => true, 'controller' => false] as $pathType => $backend) {
            foreach ($this->listFiles($pathType, $extensionKey, ['name' => '*']) as $path) {
                $name = preg_replace('/Controller$/', '', basename($path, '.php')) ?? '';
                preg_match_all('/public function (\w+)Action\s*\(/', (string)file_get_contents($path), $matches);
                $actions = array_values(array_unique($matches[1]));
                sort($actions);

                $controllers[(true === $backend ? 'Backend/' : '') . $name] = [
                    'actions' => $actions,
                    'backend' => $backend,
                    'name'    => $name,
                    'path'    => $path,
                ];
            }
        }

        ksort($controllers);

        return $controllers;
    }

    /**
     * @param string[] $labelIds
     *
     * @return AuditFinding[]
     */
    private function compareLabels(array $labelIds, string $languagePath, string $extensionPath): array
    {
        $content = (string)file_get_contents($languagePath);
        $missing = array_values(array_filter(
            $labelIds,
            static fn(string $labelId): bool => !str_contains($content, 'id="' . $labelId . '"')
        ));

        if ([] === $missing) {
            return [];
        }

        return [
            new AuditFinding(
                category: self::CATEGORY_LANGUAGE,
                severity: AuditSeverity::warning,
                message : sprintf('Label(s) used in the template are missing: %s', implode(', ', $missing)),
                path    : $this->makeRelative($languagePath, $extensionPath)
            ),
        ];
    }

    /**
     * Reads the target path of the ExtensionInformation from the `extensionFiles` catalogue instead of assuming it.
     */
    private function getExtensionInformationPath(string $extensionKey, string $extensionPath): string
    {
        $extensionFiles = $this->configurationService->getValue('extensionFiles', $extensionKey, []);

        if (is_array($extensionFiles)) {
            foreach ($extensionFiles as $group) {
                if (is_array($group) && is_string($group[self::EXTENSION_INFORMATION_SKELETON] ?? null)) {
                    return $extensionPath . $group[self::EXTENSION_INFORMATION_SKELETON];
                }
            }
        }

        return $extensionPath . 'Classes/Data/ExtensionInformation.php';
    }

    /**
     * @return string[]
     */
    private function getListValue(string $path, ?string $extensionKey): array
    {
        $value = $this->configurationService->getValue($path, $extensionKey, []);

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn(mixed $entry): string => (string)$entry, $value)));
    }

    /**
     * @param array<string, string> $placeholders
     *
     * @return string[]
     */
    private function listFiles(string $pathType, string $extensionKey, array $placeholders): array
    {
        $files = glob($this->pathResolver->resolveTargetPath($pathType, $extensionKey, $placeholders));

        if (false === $files) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * @param array{actions: string[], backend: bool, name: string, path: string} $controller
     *
     * @return string[]
     */
    private function listLanguageFiles(string $extensionKey, array $controller): array
    {
        if (true === $controller['backend']) {
            return $this->listFiles('languageBackend', $extensionKey, [
                'name' => '*',
                'path' => $controller['name'],
            ]);
        }

        return $this->listFiles('languageFrontend', $extensionKey, [
            'action'     => '*',
            'controller' => $controller['name'],
        ]);
    }

    private function makeRelative(string $absolutePath, string $extensionPath): string
    {
        return str_replace($extensionPath, '', $absolutePath);
    }

    /**
     * @param array{actions: string[], backend: bool, name: string, path: string} $controller
     */
    private function resolveLanguagePath(string $extensionKey, array $controller, string $action): string
    {
        if (true === $controller['backend']) {
            return $this->pathResolver->resolveTargetPath('languageBackend', $extensionKey, [
                'name' => $action,
                'path' => $controller['name'],
            ]);
        }

        return $this->pathResolver->resolveTargetPath('languageFrontend', $extensionKey, [
            'action'     => $action,
            'controller' => $controller['name'],
        ]);
    }

    /**
     * @param array{actions: string[], backend: bool, name: string, path: string} $controller
     */
    private function resolveTemplatePath(string $extensionKey, array $controller, string $action): string
    {
        return $this->pathResolver->resolveTargetPath(
            true === $controller['backend'] ? 'backendTemplate' : 'template',
            $extensionKey,
            [
                'action'     => ucfirst($action),
                'controller' => $controller['name'],
            ]
        );
    }
}
