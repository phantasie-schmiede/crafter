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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeServiceCommand
 *
 * Creates a service class with its constructor dependencies.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:service',
    description: 'Creates a service class'
)]
class MakeServiceCommand extends AbstractMakeClassCommand
{
    public const string CLASS_TYPE = 'service';

    protected function configure(): void
    {
        parent::configure();
        $this->addDependencyOption();
        $this->addSubPathOption();
    }

    protected function buildBlueprint(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $extensionKey,
        string         $name,
    ): ?ClassBlueprint {
        $subPath = $this->getSubPath($input, $io);

        if (null === $subPath) {
            return null;
        }

        return new ClassBlueprint(
            extensionKey: $extensionKey,
            name        : $name,
            type        : self::CLASS_TYPE,
            dependencies: $this->getDependencies($input, $extensionKey),
            subPath     : $subPath
        );
    }

    protected function getArtifactLabel(): string
    {
        return 'Service';
    }

    protected function getNameDescription(): string
    {
        return 'Name of the service without the Service suffix, e.g. Import';
    }
}
