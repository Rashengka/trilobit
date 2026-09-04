<?php

declare(strict_types=1);

/**
 * The coding standard, as a machine can apply it.
 *
 * PER-CS is the base rather than a project dialect, because this repository is
 * public and an outside contribution should not have to learn a house style
 * first. On top of it sit only the rules that carry a decision: strict types
 * everywhere, strict comparisons, and imports that are sorted so that a diff
 * shows what changed rather than where a line moved to.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/www',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/rector.php',
    ])
    // The leak guard's own test is a standalone script from before this
    // configuration existed; it has to keep running under a bare php binary.
    ->notPath('Tooling/CheckLeaksTest.php');

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'array_indentation' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
    ])
    ->setFinder($finder);
