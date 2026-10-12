<?php

use GeorgRinger\Linkvalidator2\Controller\ExclusionController;
use GeorgRinger\Linkvalidator2\Controller\ReportController;

/**
 * Definitions for modules provided by EXT:linkvalidator2
 */
return [
    'linkvalidator2_report' => [
        'parent' => 'link_management',
        'position' => ['after' => 'linkvalidator_checklinks'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/link-management/linkvalidator2',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'iconIdentifier' => 'module-linkvalidator',
        'labels' => 'linkvalidator2.module',
        'routes' => [
            '_default' => [
                'target' => ReportController::class,
            ],
            'exclusions' => [
                'target' => ExclusionController::class,
            ],
        ],
        'moduleData' => [
            'depth' => 0,
            'status' => '',
            'linkType' => '',
            'table' => '',
            'language' => '',
            'search' => '',
            'orderField' => 'page',
            'orderDirection' => 'asc',
            'exclusionDepth' => 999,
        ],
    ],
];
