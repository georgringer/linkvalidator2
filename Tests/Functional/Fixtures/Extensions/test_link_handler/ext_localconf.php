<?php

declare(strict_types=1);

use TYPO3Tests\TestLinkHandler\TestLinkHandler;

defined('TYPO3') or die();

// "checked" has a checker in this extension, "unchecked" has none
$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']['checked'] = TestLinkHandler::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']['unchecked'] = TestLinkHandler::class;

// "cached" has a checker whose results are stored, like the one for external URLs
$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']['cached'] = TestLinkHandler::class;
