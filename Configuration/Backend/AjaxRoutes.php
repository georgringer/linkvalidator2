<?php

use GeorgRinger\Linkvalidator2\Controller\CheckController;
use GeorgRinger\Linkvalidator2\Controller\ExclusionController;

/**
 * Definitions for AJAX routes provided by EXT:linkvalidator2
 */
return [
    'linkvalidator2_check_start' => [
        'path' => '/linkvalidator2/check/start',
        'methods' => ['GET'],
        'target' => CheckController::class . '::startAction',
    ],
    'linkvalidator2_check_page' => [
        'path' => '/linkvalidator2/check/page',
        'methods' => ['POST'],
        'target' => CheckController::class . '::pageAction',
    ],
    'linkvalidator2_exclusion_create' => [
        'path' => '/linkvalidator2/exclusion/create',
        'methods' => ['POST'],
        'target' => ExclusionController::class . '::createAction',
    ],
];
