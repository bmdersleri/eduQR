<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->name('*.php')
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                          => true,
        'array_syntax'                    => ['syntax' => 'short'],
        'ordered_imports'                 => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'               => true,
        'not_operator_with_successor_space' => true,
        'trailing_comma_in_multiline'     => true,
        'phpdoc_scalar'                   => true,
        'unary_operator_spaces'           => true,
        'binary_operator_spaces'          => true,
        'blank_line_before_statement'     => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'phpdoc_single_line_var_spacing'  => true,
        'phpdoc_var_without_name'         => true,
        'single_quote'                    => true,
        'no_extra_blank_lines'            => [
            'tokens' => ['extra', 'throw', 'use'],
        ],
        'declare_strict_types'            => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
