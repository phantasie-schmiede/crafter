<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Event\ModifyConfigurationEvent;
use PSBits\Crafter\Exception\CrafterException;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ConfigurationService
 *
 * Merges the Crafter configuration from up to five layers. Later layers win:
 *
 * 1. EXT:crafter/Configuration/Crafter.yaml     (shipped defaults)
 * 2. config/crafter.yaml                        (project wide)
 * 3. EXT:<extension>/Configuration/Crafter.yaml (single extension)
 * 4. runtime overrides                          (CLI options)
 * 5. ModifyConfigurationEvent                   (programmatic)
 *
 * Maps are merged recursively, lists are replaced completely. The origin of every value is tracked to make the
 * effective configuration explainable via `crafter:config:dump`.
 *
 * @package PSBits\Crafter\Service
 */
class ConfigurationService
{
    public const string DEFAULT_CONFIGURATION_FILE   = 'EXT:crafter/Configuration/Crafter.yaml';
    public const string EXTENSION_CONFIGURATION_FILE = 'EXT:%s/Configuration/Crafter.yaml';
    public const string PROJECT_CONFIGURATION_FILE   = 'crafter.yaml';
    public const string ROOT_KEY                     = 'crafter';

    /** @var array<string, array<string, mixed>> */
    private array $configurationCache = [];

    /** @var array<string, array<string, string>> */
    private array $layerCache = [];

    /** @var array<string, array<string, string>> */
    private array $originCache = [];

    /** @var array<string, mixed> */
    private array $runtimeOverrides = [];

    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly YamlFileLoader           $yamlFileLoader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(?string $extensionKey = null): array
    {
        $cacheIdentifier = $extensionKey ?? '';

        if (!isset($this->configurationCache[$cacheIdentifier])) {
            $this->load($cacheIdentifier, $extensionKey);
        }

        return $this->configurationCache[$cacheIdentifier];
    }

    /**
     * Returns all configuration layers with their resolved file path or a status marker.
     *
     * @return array<string, string>
     */
    public function getLayers(?string $extensionKey = null): array
    {
        $this->get($extensionKey);

        return $this->layerCache[$extensionKey ?? ''];
    }

    /**
     * Maps a flattened configuration path to the layer that provided its value.
     *
     * @return array<string, string>
     */
    public function getOrigins(?string $extensionKey = null): array
    {
        $this->get($extensionKey);

        return $this->originCache[$extensionKey ?? ''];
    }

    /**
     * Reads a single value via dot path, e.g. 'defaults.author' or 'paths.model'.
     */
    public function getValue(string $path, ?string $extensionKey = null, mixed $default = null): mixed
    {
        $value = $this->get($extensionKey);

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Applies CLI options as topmost layer. Must be called before the first read.
     *
     * @param array<string, mixed> $overrides
     */
    public function setRuntimeOverrides(array $overrides): void
    {
        $this->configurationCache = [];
        $this->layerCache         = [];
        $this->originCache        = [];
        $this->runtimeOverrides   = $overrides;
    }

    private function load(string $cacheIdentifier, ?string $extensionKey): void
    {
        $configuration = [];
        $layers        = [];
        $origins       = [];

        $files = [
            'crafter (default)' => self::DEFAULT_CONFIGURATION_FILE,
            'project'           => Environment::getConfigPath() . '/' . self::PROJECT_CONFIGURATION_FILE,
        ];

        if (null !== $extensionKey) {
            $files['extension:' . $extensionKey] = sprintf(self::EXTENSION_CONFIGURATION_FILE, $extensionKey);
        }

        foreach ($files as $origin => $file) {
            $absoluteFilePath = GeneralUtility::getFileAbsFileName($file);

            if ('' === $absoluteFilePath || !is_file($absoluteFilePath)) {
                $layers[$origin] = $file . ' (not found)';

                continue;
            }

            $layers[$origin] = $file;
            $layerData       = $this->yamlFileLoader->load($file)[self::ROOT_KEY] ?? [];

            if (!is_array($layerData)) {
                throw new CrafterException(
                    sprintf('Key "%s" in "%s" must contain a map.', self::ROOT_KEY, $file),
                    1757426401
                );
            }

            $configuration = $this->mergeLayer($configuration, $layerData, $origin, '', $origins);
        }

        if ([] !== $this->runtimeOverrides) {
            $layers['cli'] = 'runtime overrides';
            $configuration = $this->mergeLayer($configuration, $this->runtimeOverrides, 'cli', '', $origins);
        }

        if ([] === $configuration) {
            throw new CrafterException(
                sprintf('No Crafter configuration found. Expected key "%s" in %s.', self::ROOT_KEY, implode(
                    ', ',
                    $files
                )),
                1757426402
            );
        }

        ksort($origins);
        $this->configurationCache[$cacheIdentifier] = $configuration;
        $this->layerCache[$cacheIdentifier]         = $layers;
        $this->originCache[$cacheIdentifier]        = $origins;

        // The caches are filled before dispatching on purpose: a listener that reads the configuration itself gets
        // the state without the event layer instead of running into an endless recursion.
        $event = new ModifyConfigurationEvent($configuration, $extensionKey);
        $this->eventDispatcher->dispatch($event);
        $overrides = $event->getOverrides();

        if ([] === $overrides) {
            return;
        }

        $layers['event'] = ModifyConfigurationEvent::class;
        $configuration   = $this->mergeLayer($configuration, $overrides, 'event', '', $origins);

        ksort($origins);
        $this->configurationCache[$cacheIdentifier] = $configuration;
        $this->layerCache[$cacheIdentifier]         = $layers;
        $this->originCache[$cacheIdentifier]        = $origins;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $override
     * @param array<string, string> $origins
     *
     * @return array<string, mixed>
     */
    private function mergeLayer(
        array  $target,
        array  $override,
        string $origin,
        string $prefix,
        array  &$origins,
    ): array {
        foreach ($override as $key => $value) {
            $path = '' === $prefix ? (string)$key : $prefix . '.' . $key;

            // Maps are merged recursively, lists are replaced as a whole to keep overriding predictable.
            if (is_array($value) && !array_is_list($value)) {
                $target[$key] = $this->mergeLayer(
                    is_array($target[$key] ?? null) ? $target[$key] : [],
                    $value,
                    $origin,
                    $path,
                    $origins
                );

                continue;
            }

            $target[$key]   = $value;
            $origins[$path] = $origin;
        }

        return $target;
    }
}
