<?php

$fileHeaderComment = <<<'COMMENT'
Copyright(c)2026 TrouveMoi (https://trouvemoi.com)

Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
Tous droits réservés.

Ce code source est la propriété exclusive de Auxioma Web Agency et.
Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
COMMENT;

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('config')
    ->exclude('var')
    ->exclude('public/bundles')
    ->exclude('public/build')
    ->notPath('public/index.php')
    ->notPath('importmap.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'header_comment' => [
            'header' => $fileHeaderComment,
            'separate' => 'both',
            'comment_type' => 'PHPDoc',
        ],
        'linebreak_after_opening_tag' => true,
        'mb_str_functions' => true,
        'no_php4_constructor' => true,
        'no_unreachable_default_argument_value' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'php_unit_strict' => true,
        'phpdoc_order' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'blank_line_between_import_groups' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');