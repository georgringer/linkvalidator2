<?php

use TYPO3\CMS\Linkvalidator2\Controller\ReportController;

/**
 * Definitions for modules provided by EXT:linkvalidator2
 */
return [
    'linkvalidator2_report' => [
        'parent' => 'link_management',
        'position' => ['after' => 'linkvalidator_checklinks'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/link-management/linkvalidator2',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'iconIdentifier' => 'module-linkvalidator',
        'labels' => 'linkvalidator2.module',
        'routes' => [
            '_default' => [
                'target' => ReportController::class,
            ],
        ],
    ],
];
