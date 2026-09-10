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
use PSBits\Crafter\Utility\NamingUtility;

/**
 * Class LanguageFileGenerator
 *
 * Writes the XLIFF files of an action. Which labels an action needs is defined in crafter.actionLabels, the
 * property labels are derived from the model properties.
 *
 * @package PSBits\Crafter\Service
 */
readonly class LanguageFileGenerator
{
    public const string SKELETON_FILE                  = 'Xlf/LanguageFile';
    public const string SKELETON_TRANS_UNIT            = 'Xlf/TransUnit';
    public const string SKELETON_TRANS_UNIT_TRANSLATED = 'Xlf/TransUnitTranslated';

    public function __construct(
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    /**
     * @param array<string, string[]> $labelIds label identifiers per action, derived from the rendered templates
     */
    public function generateForController(ControllerBlueprint $blueprint, array $labelIds = []): void
    {
        $extensionKey = $blueprint->extensionKey;
        $labels       = $this->configurationService->getValue('actionLabels', $extensionKey, []);

        if (!is_array($labels)) {
            $labels = [];
        }

        foreach ($blueprint->actions as $action) {
            $ids = $labelIds[$action] ?? array_keys((array)($labels[$action] ?? []));

            if ([] === $ids) {
                continue;
            }

            $this->write($blueprint, $action, $this->buildUnits($ids, (array)($labels[$action] ?? []), $blueprint));
        }
    }

    /**
     * @param string[]             $ids
     * @param array<string, mixed> $labels
     *
     * @return array<string, string>
     */
    private function buildUnits(array $ids, array $labels, ControllerBlueprint $blueprint): array
    {
        $units = [];

        foreach ($ids as $id) {
            $units[$id] = isset($labels[$id])
                ? str_replace(
                    ['{modelName}', '{pluralName}'],
                    [
                        $this->humanize($blueprint->getModelName()),
                        $this->humanize(NamingUtility::toPlural($blueprint->getModelName())),
                    ],
                    (string)$labels[$id]
                )
                : $this->humanize($id);
        }

        ksort($units);

        return $units;
    }

    /**
     * Writes one XLIFF file per configured language. The file of a translation is prefixed with its language key,
     * so the placeholder carrying the file name has to be named.
     *
     * @param array<string, string> $placeholders         placeholders of the configured path
     * @param array<string, string> $units                translation identifier => source text
     */
    public function writeLanguageFiles(
        string $extensionKey,
        string $pathType,
        array  $placeholders,
        string $localizedPlaceholder,
        array  $units,
    ): void {
        $defaultLanguage = (string)$this->configurationService->getValue(
            'defaults.defaultLanguage',
            $extensionKey,
            'en'
        );
        $languages = (array)$this->configurationService->getValue(
            'defaults.languages',
            $extensionKey,
            [$defaultLanguage]
        );

        foreach ($languages as $language) {
            $language   = (string)$language;
            $isDefault  = $language === $defaultLanguage;
            $transUnits = [];

            foreach ($units as $id => $source) {
                $transUnits[] = rtrim($this->skeletonRenderer->render(
                    true === $isDefault ? self::SKELETON_TRANS_UNIT : self::SKELETON_TRANS_UNIT_TRANSLATED,
                    [
                        'transUnitId'     => $id,
                        'transUnitSource' => $source,
                        'transUnitTarget' => $source,
                    ],
                    $extensionKey
                ));
            }

            $localizedPlaceholders                        = $placeholders;
            $localizedPlaceholders[$localizedPlaceholder] = (true === $isDefault ? '' : $language . '.')
                . ($placeholders[$localizedPlaceholder] ?? '');
            $this->fileWriter->write(
                $this->pathResolver->resolveTargetPath($pathType, $extensionKey, $localizedPlaceholders),
                $this->skeletonRenderer->render(
                    self::SKELETON_FILE,
                    [
                        'date'                    => date('c'),
                        'defaultLanguage'         => $defaultLanguage,
                        'extensionKey'            => $extensionKey,
                        'targetLanguageAttribute' => true === $isDefault
                            ? ''
                            : "\n        target-language=\"" . $language . '"',
                        'transUnits'              => implode("\n", $transUnits),
                    ],
                    $extensionKey
                )
            );
        }
    }

    /**
     * Example: 'releaseDate' => 'Release date'
     */
    public function humanize(string $value): string
    {
        return ucfirst(trim(str_replace('_', ' ', NamingUtility::toLowerCaseUnderscored($value))));
    }

    /**
     * @param array<string, string> $units
     */
    private function write(ControllerBlueprint $blueprint, string $action, array $units): void
    {
        $this->writeLanguageFiles(
            $blueprint->extensionKey,
            'languageFrontend',
            [
                'action'     => $action,
                'controller' => $blueprint->name,
            ],
            'action',
            $units
        );
    }
}
