<?php

/**
 * La règle "header_comment" qui réinsérait automatiquement l'en-tête de copyright générique dans
 * chaque fichier a été retirée : elle entrait directement en conflit avec le remplacement de cet
 * en-tête par des notes explicatives sur les 158 fichiers de src/ (demande explicite du client).
 * Tant que cette règle restait active, tout lancement de `php-cs-fixer fix` (sans --dry-run) la
 * réinsérait silencieusement et effaçait ces notes -- vérifié en le reproduisant localement.
 * Sauvegarde complète de la configuration d'origine (avec la règle) dans
 * .php-cs-fixer.dist.php.copyright-backup, si le client souhaite un jour la remettre en place.
 */

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