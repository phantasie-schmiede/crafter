<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Data;

/**
 * Class ExtensionBlueprint
 *
 * Immutable description of the extension that is about to be created. Commands collect the values, generators
 * consume them. Passing this object around keeps follow-up commands independent of console input.
 *
 * @package PSBits\Crafter\Data
 */
readonly class ExtensionBlueprint
{
    public function __construct(
        public string $extensionKey,
        public string $extensionName,
        public string $vendor,
        public string $namespace,
        public string $composerName,
        public string $title,
        public string $packageTitle,
        public string $description,
        public string $author,
        public string $authorEmail,
        public string $license,
        public string $category,
        public string $state,
        public string $version,
        public string $gitHubOrganization,
    ) {
    }

    public function getGitHubRepository(): string
    {
        return $this->gitHubOrganization . '/' . $this->getRepositoryName();
    }

    /**
     * Example: 'budget_manager' => 'budget-manager'
     */
    public function getRepositoryName(): string
    {
        return str_replace('_', '-', $this->extensionKey);
    }

    /**
     * TypoScript and plugin signature prefix, example: 'budget_manager' => 'tx_budgetmanager'
     */
    public function getSignaturePrefix(): string
    {
        return 'tx_' . str_replace('_', '', $this->extensionKey);
    }
}
