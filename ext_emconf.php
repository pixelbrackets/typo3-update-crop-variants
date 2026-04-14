<?php

/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Update Crop Variants',
    'description' => 'TYPO3 command to add missing crop variants and optionally regenerate changed ratios in file references',
    'version' => '1.0.0',
    'state' => 'experimental',
    'constraints' => ['depends' => ['typo3' => '12.4.0-14.99.99']],
];
