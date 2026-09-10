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
 * Class BeforeSkeletonRenderEvent
 *
 * Dispatched before a stub is read and its placeholders are replaced. Listeners can add or change variables and
 * even swap the skeleton identifier to redirect the rendering to a completely different stub.
 *
 * @package PSBits\Crafter\Event
 */
final class BeforeSkeletonRenderEvent
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        private string          $identifier,
        private array           $variables,
        private readonly ?string $extensionKey,
    ) {
    }

    public function getExtensionKey(): ?string
    {
        return $this->extensionKey;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return array<string, string>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setVariable(string $name, string $value): void
    {
        $this->variables[$name] = $value;
    }

    /**
     * @param array<string, string> $variables
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }
}
