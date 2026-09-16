<?php

declare(strict_types=1);

use PSBits\DemoShop\Data\ExtensionInformation;
use PSBits\Foundation\Service\Configuration\ModuleService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

return GeneralUtility::makeInstance(ModuleService::class)
    ->buildModuleConfiguration(GeneralUtility::makeInstance(ExtensionInformation::class));
