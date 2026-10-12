<?php

declare(strict_types=1);

use GeorgRinger\Linkvalidator2\Hook\DataHandlerHook;
use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['linkvalidator2'] = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['linkvalidator2'] = DataHandlerHook::class;

// Checked in the background by messenger:consume doctrine; route to "default" to check synchronously
$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][CheckRecordsMessage::class] ??= 'doctrine';
