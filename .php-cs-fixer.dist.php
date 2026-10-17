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
        'header_comment' => [
            'header' => "Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>\nLicensed under the GNU Affero General Public License v3.0.",
        ],
    ])
    ->setFinder($finder)
;
