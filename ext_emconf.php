<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'LinkValidator (rewrite)',
    'description' => 'Checks links in records and reports broken ones.',
    'category' => 'module',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-15.0.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'GeorgRinger\\Linkvalidator2\\' => 'Classes/',
        ],
    ],
];
