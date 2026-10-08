<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'LinkValidator (rewrite)',
    'description' => 'Checks links in records and reports broken ones.',
    'category' => 'module',
    'author' => 'TYPO3 Core Team',
    'author_email' => 'typo3cms@typo3.org',
    'state' => 'alpha',
    'version' => '15.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '15.0.0-15.0.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'TYPO3\\CMS\\Linkvalidator2\\' => 'Classes/',
        ],
    ],
];
