<?php
declare(strict_types=1);

$root = getcwd() ?: throw new RuntimeException('Unable to resolve the project directory.');
$target = getenv('QA_TARGET_PATH') ?: 'src';
$path = preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $target) === 1 ? $target : $root . DIRECTORY_SEPARATOR . $target;
require_once dirname(__DIR__) . '/source-files.php';
$selectedFiles = qaReadSourceManifest();
$finder = PhpCsFixer\Finder::create()->files()->name('*.php');
if ($selectedFiles !== []) {
    $finder->append($selectedFiles);
} else {
    $finder->in($path)
        ->exclude(['_output', '_support/_generated'])
        ->notPath(['reference.php']);
}

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@Symfony' => true,
        '@PHP8x4Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'yoda_style' => false,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => null,
            'import_constants' => null,
        ],
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'single_line_throw' => false,
        'types_spaces' => ['space' => 'single', 'space_multiple_catch' => 'single'],
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => ['=>' => 'align_single_space_minimal'],
        ],
        'concat_space' => ['spacing' => 'one'],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'method_chaining_indentation' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'trailing_comma_in_multiline' => [
            'elements' => ['array_destructuring', 'arrays', 'arguments', 'parameters', 'match'],
        ],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder);
