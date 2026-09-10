<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Event;

/**
 * Class ModifyConfigurationEvent
 *
 * Fifth and topmost layer of the configuration cascade. While the four file based layers cover static overrides,
 * this event allows deciding at runtime, e.g. a different copyright block per vendor directory.
 *
 * The already merged configuration is readable, overrides are collected separately and merged afterwards with the
 * origin "event", so `crafter:config:dump --origins` keeps explaining where a value comes from.
 *
 * @package PSBits\Crafter\Event
 */
final class ModifyConfigurationEvent
{
    /** @var array<string, mixed> */
    private array $overrides = [];

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        private readonly array   $configuration,
        private readonly ?string $extensionKey,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function addOverrides(array $overrides): void
    {
        $this->overrides = array_replace_recursive($this->overrides, $overrides);
    }

    /**
     * The configuration as merged from all file based layers and the CLI options.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getExtensionKey(): ?string
    {
        return $this->extensionKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverrides(): array
    {
        return $this->overrides;
    }
}
