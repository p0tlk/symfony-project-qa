<#
.SYNOPSIS
Installs the Symfony QA rules and Composer tools into the current project.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install-project.ps1
  .\install-project.ps1 -Target app -Force
#>

[CmdletBinding()]
param(
    [string] $Target = 'src',
    [switch] $Force,
    [switch] $NoInstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Get-Location).Path
$composerJson = Join-Path $projectRoot 'composer.json'
$targetPath = Join-Path $projectRoot $Target
$qaRoot = Join-Path $projectRoot '.qa'

if (-not (Test-Path -LiteralPath $composerJson -PathType Leaf)) {
    throw "Run this installer from a project root containing composer.json."
}

if (-not (Test-Path -LiteralPath $targetPath -PathType Container)) {
    throw "Target directory does not exist: $targetPath (override it with -Target)."
}

if ((Test-Path -LiteralPath $qaRoot) -and -not $Force) {
    throw "The .qa directory already exists. Use -Force to replace the generated files."
}

$composer = Get-Command composer -ErrorAction SilentlyContinue
if (-not $NoInstall -and $null -eq $composer) {
    throw 'Composer is not available on PATH.'
}

if (-not $NoInstall) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $php) {
        throw 'PHP is not available on PATH.'
    }

    $phpVersionText = (& $php.Source -r 'echo PHP_VERSION;').Trim()
    if ([version] $phpVersionText -lt [version] '8.4.0') {
        throw "PHP 8.4 or newer is required; found PHP $phpVersionText."
    }

    $phpModules = @(& $php.Source -m)
    $missingPhpModules = @('mbstring', 'dom', 'SimpleXML') | Where-Object { $phpModules -notcontains $_ }
    if ($missingPhpModules.Count -gt 0) {
        throw "Psalm requires these missing PHP extension(s): $($missingPhpModules -join ', '). Enable them for the CLI PHP installation, then run this installer again."
    }

    if ($null -eq (Get-Command aspell -ErrorAction SilentlyContinue)) {
        throw 'GNU Aspell is required by Peck but is not available on PATH. Install Aspell and its English dictionary, then run this installer again.'
    }
}

New-Item -ItemType Directory -Path $qaRoot -Force | Out-Null

function Write-Utf8File {
    param([string] $Path, [string] $Value)

    # Windows PowerShell's "utf8" encoding adds a BOM, which breaks PHP files
    # whose strict_types declaration must be the first statement.
    $encoding = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($Path, $Value, $encoding)
}

$runner = @'
[CmdletBinding()]
param(
    [ValidateSet('full', 'fix', 'check')]
    [string] $Mode = 'full',
    [string] $Target = '__QA_TARGET__',
    [switch] $WithTests,
    [switch] $SkipPeck
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$qa = Join-Path $root '.qa'

if (-not (Test-Path (Join-Path $root 'composer.json'))) {
    throw "qa.ps1 must remain in the project root."
}

function Invoke-QaTool {
    param([string] $Name, [string] $Binary, [string[]] $Arguments, [int[]] $SuccessCodes = @(0))

    $windowsBinary = Join-Path $root "vendor\bin\$Binary.bat"
    $unixBinary = Join-Path $root "vendor\bin\$Binary"
    $command = if (Test-Path $windowsBinary) { $windowsBinary } elseif (Test-Path $unixBinary) { $unixBinary } else { $null }
    if ($null -eq $command) {
        throw "Missing vendor/bin/$Binary. Run: composer install"
    }

    Write-Host "==> $Name" -ForegroundColor Cyan
    & $command @Arguments
    $code = $LASTEXITCODE
    if ($code -notin $SuccessCodes) {
        throw "$Name failed with exit code $code."
    }
}

Push-Location $root
try {
    $env:QA_TARGET_PATH = $Target
    $fix = $Mode -in @('full', 'fix')
    $check = $Mode -in @('full', 'check')

    if ($fix) {
        Invoke-QaTool 'Rector fix' 'rector' @('process', '--no-ansi', "--config=$qa\rector.php")
        Invoke-QaTool 'PHPCBF fix' 'phpcbf' @('--no-colors', "--standard=$qa\phpcs.xml", $Target) @(0, 1, 2)
        Invoke-QaTool 'PHP-CS-Fixer fix' 'php-cs-fixer' @('fix', '--no-ansi', '--show-progress=none', "--config=$qa\php-cs-fixer.php")
    }

    if ($check) {
        Invoke-QaTool 'PHP-CS-Fixer check' 'php-cs-fixer' @('fix', '--dry-run', '--diff', '--no-ansi', '--show-progress=none', "--config=$qa\php-cs-fixer.php")
        Invoke-QaTool 'PHPCS check' 'phpcs' @('--no-colors', "--standard=$qa\phpcs.xml", '--no-cache', $Target)
        Invoke-QaTool 'Rector check' 'rector' @('process', '--dry-run', '--no-ansi', "--config=$qa\rector.php")
        Invoke-QaTool 'PHPStan' 'phpstan' @('analyse', $Target, '--no-ansi', '--no-progress', '--memory-limit=1G')
        Invoke-QaTool 'Psalm with Symfony plugin' 'psalm' @('--config=psalm.xml', '--no-progress', $Target)

        if ($WithTests) {
            $phpunit = Join-Path $root 'bin\phpunit'
            if (-not (Test-Path $phpunit)) { throw 'Missing bin/phpunit.' }
            & php $phpunit
            if ($LASTEXITCODE -ne 0) { throw "PHPUnit failed with exit code $LASTEXITCODE." }
        }

        if (-not $SkipPeck) {
            if ($null -eq (Get-Command aspell -ErrorAction SilentlyContinue)) {
                throw 'GNU Aspell is required by Peck but is not available on PATH.'
            }
            Invoke-QaTool 'Peck spelling check' 'peck' @('--no-ansi', '--config=peck.json', "--path=$Target")
        }
    }

    Write-Host 'QA completed successfully.' -ForegroundColor Green
}
finally {
    Remove-Item Env:QA_TARGET_PATH -ErrorAction SilentlyContinue
    Pop-Location
}
'@
$runner = $runner.Replace('__QA_TARGET__', $Target.Replace("'", "''"))
Write-Utf8File -Path (Join-Path $projectRoot 'qa.ps1') -Value $runner

$phpCsFixer = @'
<?php
declare(strict_types=1);

$root = getcwd() ?: throw new RuntimeException('Unable to resolve the project directory.');
$target = getenv('QA_TARGET_PATH') ?: '__QA_TARGET__';
$path = $root . DIRECTORY_SEPARATOR . $target;

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
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->files()
            ->name('*.php')
            ->in($path)
            ->exclude(['_output', '_support/_generated'])
            ->notPath(['reference.php'])
    );
'@
$phpCsFixer = $phpCsFixer.Replace('__QA_TARGET__', $Target.Replace("'", "\'"))
Write-Utf8File -Path (Join-Path $qaRoot 'php-cs-fixer.php') -Value $phpCsFixer

$rector = @'
<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\Symfony\CodeQuality\Rector\Class_\ControllerMethodInjectionToConstructorRector;

$root = getcwd() ?: throw new RuntimeException('Unable to resolve the project directory.');
$target = getenv('QA_TARGET_PATH') ?: '__QA_TARGET__';
$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'symfony-qa-rector-' . md5($root . DIRECTORY_SEPARATOR . $target);

return RectorConfig::configure()
    ->withPaths([$root . DIRECTORY_SEPARATOR . $target])
    ->withSkip([
        ControllerMethodInjectionToConstructorRector::class,
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
'@
$rector = $rector.Replace('__QA_TARGET__', $Target.Replace("'", "\'"))
Write-Utf8File -Path (Join-Path $qaRoot 'rector.php') -Value $rector

$phpcs = @'
<?xml version="1.0"?>
<ruleset name="Application">
    <description>Project-local Symfony application coding standard.</description>
    <arg name="colors"/>
    <arg name="report-width" value="auto"/>
    <arg name="encoding" value="utf-8"/>
    <arg value="sp"/>
    <arg name="extensions" value="php"/>
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/var/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
    <exclude-pattern>*/tests/_support/_generated/*</exclude-pattern>
    <rule ref="PSR12"/>
    <rule ref="SlevomatCodingStandard.TypeHints.DeclareStrictTypes">
        <properties>
            <property name="spacesCountAroundEqualsSign" value="0"/>
        </properties>
    </rule>
    <rule ref="SlevomatCodingStandard.TypeHints.ParameterTypeHint"/>
    <rule ref="SlevomatCodingStandard.TypeHints.PropertyTypeHint"/>
    <rule ref="SlevomatCodingStandard.TypeHints.ReturnTypeHint"/>
    <rule ref="SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses"/>
    <rule ref="SlevomatCodingStandard.Namespaces.UnusedUses"/>
    <rule ref="Generic.Files.LineLength">
        <properties>
            <property name="lineLimit" value="120"/>
            <property name="absoluteLineLimit" value="160"/>
            <property name="ignoreComments" value="true"/>
        </properties>
    </rule>
    <rule ref="Generic.Files.LineLength.TooLong">
        <severity>3</severity>
    </rule>
    <rule ref="Generic.PHP.ForbiddenFunctions">
        <properties>
            <property name="forbiddenFunctions" type="array">
                <element key="var_dump" value="null"/>
                <element key="print_r" value="null"/>
                <element key="dd" value="null"/>
                <element key="dump" value="null"/>
            </property>
        </properties>
    </rule>
    <rule ref="Generic.PHP.NoSilencedErrors">
        <properties>
            <property name="error" value="true"/>
        </properties>
    </rule>
    <rule ref="Generic.CodeAnalysis.EmptyStatement"/>
    <rule ref="Generic.CodeAnalysis.UnconditionalIfStatement"/>
    <rule ref="Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence"/>
    <rule ref="Squiz.PHP.NonExecutableCode"/>
</ruleset>
'@
Write-Utf8File -Path (Join-Path $qaRoot 'phpcs.xml') -Value $phpcs

$peck = @'
{
    "preset": "base",
    "ignore": {
        "words": [
            "api", "amq", "auth", "authenticator", "backend", "cache", "charset",
            "cli", "config", "csrf", "datetime", "deprecation", "doctrine", "dto",
            "ecs", "endpoint", "enum", "env", "filesystem", "frontend", "hostname",
            "http", "https", "idempotency", "json", "jwks", "jwt", "keycloak",
            "middleware", "mq", "namespace", "nullable", "oauth", "oa", "php",
            "phpcbf", "phpcs", "phpdoc", "phpstan", "phpunit", "psalm", "readonly", "rector",
            "repo", "requeue", "serializer", "schemas", "slevomat", "symfony", "timestamp",
            "trait", "twig", "uri", "uuid", "validator", "webhook", "workflow", "wsp",
            "yaml"
        ],
        "paths": ["vendor", "node_modules", "tests", "var"]
    }
}
'@
Write-Utf8File -Path (Join-Path $projectRoot 'peck.json') -Value $peck

$psalmTarget = [System.Security.SecurityElement]::Escape(($Target -replace '\\', '/'))
$psalm = @'
<?xml version="1.0"?>
<psalm
    cacheDirectory="var/cache/psalm"
    ensureOverrideAttribute="false"
    errorLevel="3"
    resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
    findUnusedBaselineEntry="true"
    findUnusedCode="true"
>
    <projectFiles>
        <directory name="__QA_TARGET_XML__" />
        <ignoreFiles>
            <directory name="__QA_TARGET_XML__/Entity" />
            <file name="__QA_TARGET_XML__/Kernel.php" />
        </ignoreFiles>
    </projectFiles>
    <plugins>
        <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" />
    </plugins>
</psalm>
'@
$psalm = $psalm.Replace('__QA_TARGET_XML__', $psalmTarget)
Write-Utf8File -Path (Join-Path $projectRoot 'psalm.xml') -Value $psalm

if (-not $NoInstall) {
    Write-Host 'Installing project-local QA dependencies...' -ForegroundColor Cyan
    & $composer.Source config --no-interaction allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
    if ($LASTEXITCODE -ne 0) {
        throw "Composer plugin configuration failed with exit code $LASTEXITCODE."
    }

    & $composer.Source require --dev --no-interaction --no-progress --with-all-dependencies `
        rector/rector phpstan/phpstan squizlabs/php_codesniffer friendsofphp/php-cs-fixer `
        slevomat/coding-standard dealerdirect/phpcodesniffer-composer-installer peckphp/peck `
        vimeo/psalm psalm/plugin-symfony
    if ($LASTEXITCODE -ne 0) {
        throw "Composer failed with exit code $LASTEXITCODE. Generated files were kept."
    }
}

Write-Host "Installed project QA files in $projectRoot" -ForegroundColor Green
Write-Host 'Run: .\qa.ps1 check' -ForegroundColor Green
