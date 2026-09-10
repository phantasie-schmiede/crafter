<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\ExtensionBlueprint;
use PSBits\Crafter\Service\ConfigurationService;
use PSBits\Crafter\Service\ExtensionGenerator;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\PathResolver;
use PSBits\Crafter\Utility\NamingUtility;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeExtensionCommand
 *
 * Creates the complete base structure of a new TYPO3 extension.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:extension',
    description: 'Creates the base structure of a new TYPO3 extension'
)]
class MakeExtensionCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_EXTENSION_KEY     = 'extensionKey';
    public const string OPTION_AUTHOR              = 'author';
    public const string OPTION_AUTHOR_EMAIL        = 'author-email';
    public const string OPTION_CATEGORY            = 'category';
    public const string OPTION_COMPOSER_NAME       = 'composer-name';
    public const string OPTION_DESCRIPTION         = 'description';
    public const string OPTION_GITHUB_ORGANIZATION = 'github-organization';
    public const string OPTION_GROUPS              = 'groups';
    public const string OPTION_LICENSE             = 'license';
    public const string OPTION_STATE               = 'state';
    public const string OPTION_TITLE               = 'title';
    public const string OPTION_VENDOR              = 'vendor';
    public const string OPTION_VERSION             = 'package-version';

    private const string EXTENSION_KEY_PATTERN = '/^[a-z][a-z0-9_]{2,}$/';

    public function __construct(
        protected readonly ConfigurationService $configurationService,
        protected readonly ExtensionGenerator   $extensionGenerator,
        protected readonly FileWriter           $fileWriter,
        protected readonly PathResolver         $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(
            self::ARGUMENT_EXTENSION_KEY,
            InputArgument::OPTIONAL,
            'Extension key in lower_underscored notation, e.g. budget_manager'
        );

        foreach ([
            self::OPTION_AUTHOR              => 'Author name',
            self::OPTION_AUTHOR_EMAIL        => 'Author email address',
            self::OPTION_CATEGORY            => 'Extension category for ext_emconf.php',
            self::OPTION_COMPOSER_NAME       => 'Composer package name, e.g. psbits/budget-manager',
            self::OPTION_DESCRIPTION         => 'Short description of the extension',
            self::OPTION_GITHUB_ORGANIZATION => 'GitHub organization used in generated URLs',
            self::OPTION_GROUPS              => 'Comma separated list of file groups to create',
            self::OPTION_LICENSE             => 'SPDX license identifier',
            self::OPTION_STATE               => 'Extension state for ext_emconf.php',
            self::OPTION_TITLE               => 'Human readable extension title',
            self::OPTION_VENDOR              => 'Vendor namespace, e.g. PSBits',
            self::OPTION_VERSION             => 'Initial version number',
        ] as $name => $description) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $description);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = $this->getIo($input, $output);
        $extensionKey = $this->determineExtensionKey($input, $io);

        if (null === $extensionKey) {
            return Command::FAILURE;
        }

        $extensionPath = $this->pathResolver->resolveExtensionPath($extensionKey);

        if (is_dir($extensionPath) && false === (bool)$input->getOption(self::OPTION_FORCE)) {
            $io->warning(sprintf(
                'Directory "%s" already exists. Existing files are skipped, use --force to overwrite them.',
                $this->makeRelative($extensionPath)
            ));
        }

        $blueprint = $this->buildBlueprint($input, $io, $extensionKey);
        $groups    = $this->determineGroups($input, $io, $extensionKey);
        $this->showSummary($io, $blueprint, $extensionPath, $groups);

        if (false === $io->confirm('Create the extension now?')) {
            $io->note('Aborted, nothing has been written.');

            return Command::SUCCESS;
        }

        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->showResults($io, $this->extensionGenerator->generate($blueprint, $groups));
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        $this->showNextSteps($io, $blueprint, $extensionPath);

        return Command::SUCCESS;
    }

    private function buildBlueprint(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $extensionKey,
    ): ExtensionBlueprint {
        $extensionName = NamingUtility::toUpperCamelCase($extensionKey);
        $humanizedName = trim((string)preg_replace('/(?<!^)[A-Z]/', ' $0', $extensionName));
        $vendor        = $this->ask(
            $input,
            $io,
            self::OPTION_VENDOR,
            'Vendor namespace',
            $this->getDefault($extensionKey, 'vendor', 'PSBits')
        );

        return new ExtensionBlueprint(
            extensionKey      : $extensionKey,
            extensionName     : $extensionName,
            vendor            : $vendor,
            namespace         : NamingUtility::buildNamespace($vendor, $extensionName),
            composerName      : $this->ask(
                $input,
                $io,
                self::OPTION_COMPOSER_NAME,
                'Composer package name',
                strtolower($vendor) . '/' . str_replace('_', '-', $extensionKey)
            ),
            title             : $this->ask(
                $input,
                $io,
                self::OPTION_TITLE,
                'Extension title',
                $vendor . ' | ' . $humanizedName
            ),
            packageTitle      : trim($vendor . ' ' . $humanizedName),
            description       : $this->ask($input, $io, self::OPTION_DESCRIPTION, 'Description', $humanizedName),
            author            : $this->ask(
                $input,
                $io,
                self::OPTION_AUTHOR,
                'Author',
                $this->getDefault($extensionKey, 'author', '')
            ),
            authorEmail       : $this->ask(
                $input,
                $io,
                self::OPTION_AUTHOR_EMAIL,
                'Author email',
                $this->getDefault($extensionKey, 'authorEmail', '')
            ),
            license           : $this->ask(
                $input,
                $io,
                self::OPTION_LICENSE,
                'License',
                $this->getDefault($extensionKey, 'license', 'GPL-3.0-or-later')
            ),
            category          : $this->ask(
                $input,
                $io,
                self::OPTION_CATEGORY,
                'Category',
                $this->getDefault($extensionKey, 'emConfCategory', 'plugin')
            ),
            state             : $this->ask(
                $input,
                $io,
                self::OPTION_STATE,
                'State',
                $this->getDefault($extensionKey, 'emConfState', 'alpha')
            ),
            version           : $this->ask(
                $input,
                $io,
                self::OPTION_VERSION,
                'Version',
                $this->getDefault($extensionKey, 'extensionVersion', '0.1.0')
            ),
            gitHubOrganization: $this->ask(
                $input,
                $io,
                self::OPTION_GITHUB_ORGANIZATION,
                'GitHub organization',
                $this->getDefault($extensionKey, 'gitHubOrganization', '')
            ),
        );
    }

    private function ask(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $option,
        string         $question,
        string         $default,
    ): string {
        $value = $input->getOption($option);

        if (is_string($value) && '' !== $value) {
            return $value;
        }

        if (false === $input->isInteractive()) {
            return $default;
        }

        return (string)$io->ask($question, '' === $default ? null : $default);
    }

    private function determineExtensionKey(InputInterface $input, SymfonyStyle $io): ?string
    {
        $extensionKey = $input->getArgument(self::ARGUMENT_EXTENSION_KEY);

        if ((!is_string($extensionKey) || '' === $extensionKey) && true === $input->isInteractive()) {
            $extensionKey = (string)$io->ask('Extension key (lower_underscored)');
        }

        if (!is_string($extensionKey) || 1 !== preg_match(self::EXTENSION_KEY_PATTERN, $extensionKey)) {
            $io->error(
                'The extension key must be at least three characters long and may only contain lowercase letters,'
                . ' digits and underscores, starting with a letter.'
            );

            return null;
        }

        return $extensionKey;
    }

    /**
     * @return string[]
     */
    private function determineGroups(InputInterface $input, SymfonyStyle $io, string $extensionKey): array
    {
        $availableGroups = $this->extensionGenerator->getAvailableGroups($extensionKey);
        $defaultGroups   = $this->extensionGenerator->getDefaultGroups($extensionKey);
        $selection       = $input->getOption(self::OPTION_GROUPS);

        if (is_string($selection) && '' !== $selection) {
            return array_values(array_filter(array_map('trim', explode(',', $selection))));
        }

        if (false === $input->isInteractive()) {
            return $defaultGroups;
        }

        $groups = [ExtensionGenerator::CORE_GROUP];

        foreach ($availableGroups as $group) {
            if (ExtensionGenerator::CORE_GROUP === $group) {
                continue;
            }

            if (true === $io->confirm(
                sprintf('Create the "%s" files?', $group),
                in_array($group, $defaultGroups, true)
            )) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function getDefault(string $extensionKey, string $key, string $fallback): string
    {
        $value = $this->configurationService->getValue('defaults.' . $key, $extensionKey, $fallback);

        return is_scalar($value) ? (string)$value : $fallback;
    }

    private function showNextSteps(SymfonyStyle $io, ExtensionBlueprint $blueprint, string $extensionPath): void
    {
        $io->success(sprintf('Extension "%s" has been created.', $blueprint->extensionKey));
        $io->section('Next steps');
        $io->listing([
            sprintf(
                'Register the package: composer config repositories.%s path %s',
                $blueprint->getRepositoryName(),
                $this->makeRelative($extensionPath)
            ),
            sprintf('Require it: composer require %s:@dev', $blueprint->composerName),
            sprintf('Install the tooling: cd %s && composer install', $this->makeRelative($extensionPath)),
            sprintf('Add a model: vendor/bin/typo3 crafter:make:model -e %s', $blueprint->extensionKey),
        ]);
    }

    /**
     * @param string[] $groups
     */
    private function showSummary(
        SymfonyStyle       $io,
        ExtensionBlueprint $blueprint,
        string             $extensionPath,
        array              $groups,
    ): void {
        $io->section('Summary');
        $io->definitionList(
            ['Extension key' => $blueprint->extensionKey],
            ['Title' => $blueprint->title],
            ['Namespace' => $blueprint->namespace],
            ['Composer name' => $blueprint->composerName],
            ['Target directory' => $this->makeRelative($extensionPath)],
            ['File groups' => implode(', ', $groups)],
            ['Files' => (string)count($this->extensionGenerator->getFiles($blueprint->extensionKey, $groups))],
        );
    }
}
