<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

/*
 * Rector is ADVISORY here, not a CI gate (TWT-186). Run `composer rector` (dry-run) and review the
 * diff — never auto-apply to the deterministic core (`app/Sim`) blindly: a mechanical "idiom upgrade"
 * can swap a pure helper for a framework-coupled one and break same-seed reproducibility.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ]);
