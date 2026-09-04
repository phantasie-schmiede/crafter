<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

call_user_func(static function (): void {
    ExtensionManagementUtility::addStaticFile(
        'crafter',
        'Configuration/TypoScript',
        'PSBits Crafter'
    );
});
