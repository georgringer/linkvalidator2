<?php

use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionLabel;

return [
    'ctrl' => [
        'title' => 'linkvalidator2.db:exclusion',
        'label' => 'value',
        // Fields the title is built from, also loaded by record lists
        'label_alt' => 'type,target_page,reason',
        'label_userFunc' => ExclusionLabel::class . '->getTitle',
        'descriptionColumn' => 'reason',
        'type' => 'type',
        'typeicon_column' => 'type',
        'typeicon_classes' => [
            'default' => 'actions-ban',
        ],
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'type, value',
        'rootLevel' => -1,
        'versioningWS' => false,
        'groupName' => 'system',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
    ],
    'types' => [
        'url' => [
            'showitem' => '--div--;core.form.tabs:general, type, value, reason, --div--;core.form.tabs:access, --palette--;;visibility',
            'columnsOverrides' => [
                'value' => [
                    'label' => 'linkvalidator2.db:exclusion.url',
                ],
            ],
        ],
        'page' => [
            'showitem' => '--div--;core.form.tabs:general, type, target_page, reason, --div--;core.form.tabs:access, --palette--;;visibility',
        ],
        'all' => [
            'showitem' => '--div--;core.form.tabs:general, type, reason, --div--;core.form.tabs:access, --palette--;;visibility',
        ],
        // Fallback for types no longer available, e.g. of a removed extension: change or delete
        '1' => [
            'showitem' => '--div--;core.form.tabs:general, type, reason, --div--;core.form.tabs:access, --palette--;;visibility',
        ],
    ],
    'palettes' => [
        'visibility' => [
            'showitem' => 'hidden, --linebreak--, starttime, endtime',
        ],
    ],
    'columns' => [
        'type' => [
            'label' => 'linkvalidator2.db:exclusion.type',
            'description' => 'linkvalidator2.db:exclusion.type.description',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'linkvalidator2.db:exclusion.type.url', 'value' => 'url', 'icon' => 'actions-ban'],
                    ['label' => 'linkvalidator2.db:exclusion.type.page', 'value' => 'page', 'icon' => 'actions-ban'],
                    ['label' => 'linkvalidator2.db:exclusion.type.all', 'value' => 'all', 'icon' => 'actions-ban'],
                ],
                'default' => 'url',
            ],
        ],
        'value' => [
            'label' => 'linkvalidator2.db:exclusion.value',
            'description' => 'linkvalidator2.db:exclusion.url.description',
            'config' => [
                'type' => 'input',
                'placeholder' => 'example.org/path',
                'size' => 60,
                'max' => 2048,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'target_page' => [
            'label' => 'linkvalidator2.db:exclusion.target_page',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'relationship' => 'manyToOne',
                'maxitems' => 1,
                'size' => 1,
                'required' => true,
            ],
        ],
        'reason' => [
            'label' => 'linkvalidator2.db:exclusion.reason',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 40,
            ],
        ],
    ],
];
