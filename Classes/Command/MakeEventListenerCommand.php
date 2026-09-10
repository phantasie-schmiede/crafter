<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Service\ClassGenerator;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\SkeletonRenderer;
use PSBits\Crafter\Utility\PhpUtility;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeEventListenerCommand
 *
 * Creates a PSR-14 event listener. The listener is registered through the AsEventListener attribute, so no entry in
 * Configuration/Services.yaml is needed.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:event-listener',
    description: 'Creates a PSR-14 event listener'
)]
class MakeEventListenerCommand extends AbstractMakeClassCommand
{
    public const string CLASS_TYPE      = 'eventListener';
    public const string OPTION_EVENT    = 'event';
    public const string SKELETON_INVOKE = 'Php/InvokeMethod';

    public function __construct(
        ClassGenerator                      $classGenerator,
        FileWriter                          $fileWriter,
        protected readonly SkeletonRenderer $skeletonRenderer,
    ) {
        parent::__construct($classGenerator, $fileWriter);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addDependencyOption();
        $this->addOption(
            self::OPTION_EVENT,
            'E',
            InputOption::VALUE_REQUIRED,
            'Fully qualified class name of the event to listen to'
        );
    }

    protected function buildBlueprint(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $extensionKey,
        string         $name,
    ): ?ClassBlueprint {
        $event = $this->determineEvent($input, $io);

        if (null === $event) {
            return null;
        }

        return new ClassBlueprint(
            extensionKey: $extensionKey,
            name        : $name,
            type        : self::CLASS_TYPE,
            dependencies: $this->getDependencies($input, $extensionKey),
            imports     : [$event],
            methods     : [
                rtrim($this->skeletonRenderer->render(
                    self::SKELETON_INVOKE,
                    [
                        'eventClass' => PhpUtility::getShortClassName($event),
                        'methodBody' => '',
                    ],
                    $extensionKey
                )),
            ]
        );
    }

    protected function getArtifactLabel(): string
    {
        return 'Event listener';
    }

    protected function getNameDescription(): string
    {
        return 'Name of the listener, e.g. ContactPersistenceListener';
    }

    private function determineEvent(InputInterface $input, SymfonyStyle $io): ?string
    {
        $event = $input->getOption(self::OPTION_EVENT);

        if ((!is_string($event) || '' === $event) && true === $input->isInteractive()) {
            $event = (string)$io->ask(
                'Fully qualified class name of the event',
                'TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent'
            );
        }

        $event = is_string($event) ? trim($event, '\\') : '';

        if ('' === $event || false === str_contains($event, '\\')) {
            $io->error('The event must be given as a fully qualified class name.');

            return null;
        }

        return $event;
    }
}
