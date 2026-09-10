<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'misc',
    'clearCacheOnLoad' => true,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'foundation' => '3.2',
            'php'        => '8.3',
            'typo3'      => '13.4.0-13.4.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Code generator for TYPO3 extensions',
    'state'            => 'alpha',
    'title'            => 'PSBits | Crafter',
    'version'          => '1.0.0',
];
