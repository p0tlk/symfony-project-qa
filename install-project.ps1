<#
.SYNOPSIS
Install project-local QA commands, reports, and development tools.
.EXAMPLE
  & C:\path\to\symfony-project-qa\install-project.ps1
  & C:\path\to\symfony-project-qa\install-project.ps1 -Target app -Force
  & C:\path\to\symfony-project-qa\install-project.ps1 -Plain
#>
[CmdletBinding()]
param(
    [string] $Target,
    [switch] $Plain,
    [switch] $Force,
    [switch] $NoInstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'installer-common.ps1')

$projectRoot = (Get-Location).Path
$qaRoot = Join-Path $projectRoot '.qa'
$plainMode = $Plain.IsPresent -or -not (Test-Path -LiteralPath (Join-Path $projectRoot 'composer.json') -PathType Leaf)
if (-not $Target) {
    $Target = if ($plainMode) { '.' } elseif (Test-Path -LiteralPath (Join-Path $projectRoot 'src') -PathType Container) { 'src' } else { 'app' }
}
$targetPath = if ([System.IO.Path]::IsPathRooted($Target)) { $Target } else { Join-Path $projectRoot $Target }
if (-not (Test-Path -LiteralPath $targetPath -PathType Container)) {
    throw "Target directory does not exist: $targetPath. Select it with -Target, or use -Plain for root scripts."
}
if (-not $plainMode -and (Resolve-Path -LiteralPath $targetPath).Path.TrimEnd([char[]] '\/') -eq $projectRoot.TrimEnd([char[]] '\/')) {
    throw 'Use -Plain to analyse PHP scripts in the project root, or select a source folder with -Target.'
}
if ((Test-Path -LiteralPath $qaRoot) -and -not $Force) {
    throw 'The .qa directory already exists. Use -Force to update generated runtime and .qa configs.'
}
$composerCommand = if ($NoInstall) { $null } else { Assert-QaRequirements }

Copy-QaRuntime $PSScriptRoot (Join-Path $qaRoot 'runtime')
Write-QaFile (Join-Path $qaRoot 'settings.json') (([ordered]@{ target = ($Target -replace '\\', '/'); plain = [bool] $plainMode } | ConvertTo-Json) + "`n")
foreach ($configName in @('rector', 'php-cs-fixer')) {
    Write-QaFile (Join-Path $qaRoot ($configName + '.php')) ("<?php`ndeclare(strict_types=1);`nreturn require __DIR__ . '/runtime/defaults/$configName.php';`n")
}
$phpcsDefault = if ($plainMode) { 'phpcs-plain.xml' } else { 'phpcs.xml' }
Write-QaFile (Join-Path $qaRoot 'phpcs.xml') ("<?xml version=`"1.0`"?>`n<ruleset name=`"Project QA`"><rule ref=`"./runtime/defaults/$phpcsDefault`" /></ruleset>`n")
$peckConfig = Join-Path $projectRoot 'peck.json'
if (-not (Test-Path -LiteralPath $peckConfig)) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'runtime/defaults/peck.json') -Destination $peckConfig
}
Write-QaLaunchers $projectRoot '.qa/runtime/qa.php' -ProjectRoot

if ($plainMode) {
    Install-QaIsolatedTools $PSScriptRoot (Join-Path $qaRoot 'tools') $composerCommand -NoInstall:$NoInstall
} elseif (-not $NoInstall) {
    Invoke-QaComposer $composerCommand -Arguments @('config', '--no-interaction', 'allow-plugins.dealerdirect/phpcodesniffer-composer-installer', 'true')
    $manifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'tools/composer.json') -Raw | ConvertFrom-Json
    $packages = @($manifest.require.PSObject.Properties | Where-Object { $_.Name -ne 'php' } | ForEach-Object { $_.Name + ':' + $_.Value })
    Invoke-QaComposer $composerCommand -Arguments (@('require', '--dev', '--no-interaction', '--no-progress', '--with-all-dependencies') + $packages)
}

Write-Host "Installed QA commands and runtime in $projectRoot" -ForegroundColor Green
if ($NoInstall) {
    $installCommand = if ($plainMode) { 'composer install --working-dir=.qa/tools' } else { 'Rerun this installer with -Force to install the required QA development dependencies.' }
    Write-Host "Files only; dependencies were not installed. Next: $installCommand"
}
Write-Host 'Run: .\qa.bat check (or .\qa-php.bat for explicit plain PHP mode).' -ForegroundColor Green
