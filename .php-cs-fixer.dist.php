<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/public', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true])
    ->setLineEnding("\n")
    ->setUsingCache(false)
    ->setFinder($finder);
