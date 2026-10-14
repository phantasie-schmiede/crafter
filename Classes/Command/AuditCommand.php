<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\AuditFinding;
use PSBits\Crafter\Enum\AuditSeverity;
use PSBits\Crafter\Service\ExtensionAuditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Class AuditCommand
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:audit',
    description: 'Checks an extension against the conventions Crafter generates by'
)]
class AuditCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_EXTENSION_KEY = 'extensionKey';
    public const string OPTION_CATEGORY        = 'category';
    public const string OPTION_STRICT          = 'strict';

    public function __construct(
        protected readonly ExtensionAuditor $extensionAuditor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(
            <<<'HELP'
            Checks an extension against the conventions Crafter generates by and reports every
            deviation with a severity, grouped by category. The findings do not modify any file.

            The extension is given as argument or via --extension. The repeatable option
            --category limits the audit to specific categories. With --strict, warnings fail the
            run, not only errors.

            Example:
              crafter:audit budget_manager --category model --strict
            HELP
        );
        $this->addArgument(
            self::ARGUMENT_EXTENSION_KEY,
            InputArgument::OPTIONAL,
            'Extension key to audit, alternatively given via --extension'
        );
        $this->addOption(
            self::OPTION_CATEGORY,
            'c',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Limit the audit to these categories, may be repeated'
        );
        $this->addOption(
            self::OPTION_STRICT,
            null,
            InputOption::VALUE_NONE,
            'Also fail on warnings, not only on errors'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->determineExtensionKey($input);

        if (null === $extensionKey) {
            $io->error('No extension given. Use the argument or --extension.');

            return Command::FAILURE;
        }

        $categories = array_values(array_filter((array)$input->getOption(self::OPTION_CATEGORY)));
        $findings   = $this->extensionAuditor->audit($extensionKey, array_map(strval(...), $categories));

        $io->title(sprintf('Audit of "%s"', $extensionKey));

        if ([] === $findings) {
            $io->success('No findings, the extension follows all checked conventions.');

            return Command::SUCCESS;
        }

        $this->showFindings($io, $findings);

        return $this->determineExitCode($findings, (bool)$input->getOption(self::OPTION_STRICT));
    }

    /**
     * @param AuditFinding[] $findings
     */
    private function determineExitCode(array $findings, bool $strict): int
    {
        foreach ($findings as $finding) {
            if (AuditSeverity::error === $finding->severity) {
                return Command::FAILURE;
            }

            if (true === $strict && AuditSeverity::warning === $finding->severity) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function determineExtensionKey(InputInterface $input): ?string
    {
        $extensionKey = $input->getArgument(self::ARGUMENT_EXTENSION_KEY);

        if (is_string($extensionKey) && '' !== $extensionKey) {
            return $extensionKey;
        }

        return $this->getExtensionKey($input);
    }

    /**
     * @param AuditFinding[] $findings
     */
    private function showFindings(SymfonyStyle $io, array $findings): void
    {
        $counts = [];
        $rows   = [];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
            $rows[]                            = [
                $finding->severity->getLabel(),
                $finding->category,
                $finding->message,
                $finding->path,
            ];
        }

        $io->table(['Severity', 'Category', 'Finding', 'File'], $rows);

        $hints = [];

        foreach ($findings as $finding) {
            if ('' !== $finding->hint) {
                $hints['vendor/bin/typo3 ' . $finding->hint] = true;
            }
        }

        if ([] !== $hints) {
            $io->section('Suggested commands');
            $io->listing(array_keys($hints));
        }

        $io->writeln(
            sprintf(
                '%d error(s), %d warning(s), %d notice(s).',
                $counts[AuditSeverity::error->value] ?? 0,
                $counts[AuditSeverity::warning->value] ?? 0,
                $counts[AuditSeverity::notice->value] ?? 0
            )
        );
    }
}
