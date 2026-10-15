<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        'src',
        'tests',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
