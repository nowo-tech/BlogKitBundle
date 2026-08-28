<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/demo',
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/src/Doctrine/AuditableEntityListener.php',
        ],
        // Symfony 7.4 UserInterface still requires eraseCredentials(); removed in Symfony 8.
        RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/tests/Support/TestUser.php',
        ],
    ])
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
