<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\Data;

use PSBits\DemoShop\Controller\Backend\ImportController;
use PSBits\Foundation\Data\AbstractExtensionInformation;
use PSBits\Foundation\Data\MainModuleConfiguration;
use PSBits\Foundation\Data\ModuleConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ExtensionInformation
 *
 * @package PSBits\DemoShop\Data
 */
class ExtensionInformation extends AbstractExtensionInformation
{
    public function __construct()
    {
        parent::__construct();

        $mainModuleKey = $this->buildModuleKeyPrefix() . 'main';
        $this->addMainModule(
            GeneralUtility::makeInstance(
                MainModuleConfiguration::class,
                key: $mainModuleKey
            )
        );

        $this->addModule(
            GeneralUtility::makeInstance(
                ModuleConfiguration::class,
                controllers : [ImportController::class],
                key         : $this->buildModuleKeyPrefix() . 'import',
                parentModule: $mainModuleKey
            )
        );
    }
}
