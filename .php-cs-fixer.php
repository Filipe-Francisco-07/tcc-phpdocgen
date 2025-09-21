<?php

$dirs = array_values(array_filter(['src','bin','tools','tests'], 'is_dir'));

$finder = PhpCsFixer\Finder::create()
    ->in($dirs)
    ->exclude(['vendor','build','docs']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'line_ending' => true,
        'no_trailing_whitespace' => true,
        'no_unused_imports' => true,
        'single_blank_line_at_eof' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
