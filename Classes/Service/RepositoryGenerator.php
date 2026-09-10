<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Service;

use PSBits\Crafter\Data\WriteResult;
use PSBits\Crafter\Utility\PhpUtility;

/**
 * Class RepositoryGenerator
 *
 * @package PSBits\Crafter\Service
 */
readonly class RepositoryGenerator
{
    public const string REPOSITORY_SUB_NAMESPACE = 'Domain\Repository';
    public const string SKELETON_REPOSITORY      = 'Php/Repository';

    public function __construct(
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    public function generate(string $extensionKey, string $modelName): WriteResult
    {
        return $this->fileWriter->write(
            $this->pathResolver->resolveTargetPath('repository', $extensionKey, ['name' => $modelName]),
            $this->render($extensionKey, $modelName)
        );
    }

    public function render(string $extensionKey, string $modelName): string
    {
        $namespace = $this->pathResolver->resolveNamespace(
            $extensionKey,
            (string)$this->configurationService->getValue('defaults.vendor', $extensionKey, '')
        );
        $parentClassName = (string)$this->configurationService->getValue(
            'defaults.repositoryExtends',
            $extensionKey,
            'TYPO3\CMS\Extbase\Persistence\Repository'
        );
        $repositoryNamespace = $namespace . '\\' . self::REPOSITORY_SUB_NAMESPACE;

        return $this->skeletonRenderer->render(
            self::SKELETON_REPOSITORY,
            [
                'classBody'           => '',
                'className'           => $modelName,
                'imports'             => PhpUtility::renderImports(
                    [
                        $namespace . '\\' . ModelGenerator::MODEL_SUB_NAMESPACE . '\\' . $modelName,
                        $parentClassName,
                    ],
                    $repositoryNamespace
                ),
                'parentClass'         => PhpUtility::getShortClassName($parentClassName),
                'repositoryNamespace' => $repositoryNamespace,
            ],
            $extensionKey
        );
    }
}
