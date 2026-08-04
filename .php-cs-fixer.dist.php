<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true,
        'concat_space' => ['spacing' => 'one'],
        'blank_line_after_opening_tag' => true,
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => false,
        'return_type_declaration' => ['space_before' => 'none'],
        'single_class_element_per_statement' => true,
        'single_import_per_statement' => true,
        'whitespace_after_comma_in_array' => ['ensure_single_space' => true],
    ])
    ->setFinder($finder);
