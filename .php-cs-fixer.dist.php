<?php

declare(strict_types=1);

/*
 * Code-style configuration for the Apify PHP client. Enforces PSR-12 plus a small
 * set of widely-used hygiene rules. Run `composer fix` to apply, `composer lint` to check.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_trailing_whitespace' => true,
    ])
    ->setFinder($finder);
