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
 * Class AfterSkeletonRenderEvent
 *
 * Dispatched once all placeholders of a stub have been replaced. Listeners receive the finished content and can
 * post process it, e.g. append a generated marker or rewrite a namespace.
 *
 * Note that this event fires for every stub, including the nested ones a generator assembles a file from.
 *
 * @package PSBits\Crafter\Event
 */
final class AfterSkeletonRenderEvent
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        private readonly string  $identifier,
        private string           $content,
        private readonly array   $variables,
        private readonly ?string $extensionKey,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
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

    public function setContent(string $content): void
    {
        $this->content = $content;
    }
}
