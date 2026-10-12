<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;

$root = getcwd() ?: throw new RuntimeException('Unable to resolve the project directory.');
$target = getenv('QA_TARGET_PATH') ?: 'src';
$path = preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $target) === 1 ? $target : $root . DIRECTORY_SEPARATOR . $target;
$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'symfony-qa-rector-' . md5($root . DIRECTORY_SEPARATOR . $target);
require_once dirname(__DIR__) . '/source-files.php';
if (getenv('QA_PLAIN') === '1') {
    return RectorConfig::configure()
        ->withPaths(qaReadSourceManifest())
        ->withSkip([FlipTypeControlToUseExclusiveTypeRector::class, ThrowWithPreviousExceptionRector::class])
        ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true, privatization: true, earlyReturn: true)
        ->withImportNames()
        ->withCache($cache . '-plain')
        ->withParallel(timeoutSeconds: 300)
        ->withPhpVersion(80400)
        ->withPhpSets(php84: true);
}

return RectorConfig::configure()
    ->withPaths([$path])
    ->withSkip([
        FlipTypeControlToUseExclusiveTypeRector::class,
        ThrowWithPreviousExceptionRector::class,
    ])
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withImportNames()
    ->withCache($cache)
    ->withParallel(timeoutSeconds: 300)
    ->withPhpSets();
