<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/tests',
        // The provider's static helpers are deliberately pure (no instance state);
        // FilamentAnnouncementsForHookTest invokes them without a receiver.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);
