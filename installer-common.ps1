Set-StrictMode -Version Latest

function Write-QaFile {
    param([string] $Path, [string] $Value)
    $parent = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $parent -PathType Container)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($Path, $Value, [System.Text.UTF8Encoding]::new($false))
}

function Assert-QaRequirements {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $composerCommand = Get-Command composer -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand -or $null -eq $composerCommand) {
        throw 'PHP 8.4+ and Composer 2 must be available on PATH to install QA tools.'
    }
    $phpVersion = (& $phpCommand.Source -r 'echo PHP_VERSION;').Trim()
    if ($LASTEXITCODE -ne 0 -or [version] $phpVersion -lt [version] '8.4.0') {
        throw "PHP 8.4+ is required; found $phpVersion."
    }
    $modules = @(& $phpCommand.Source -m)
    $missing = @(@('mbstring', 'dom', 'SimpleXML', 'xml', 'xmlwriter') | Where-Object { $modules -notcontains $_ })
    if ($missing.Count -gt 0) {
        throw "Enable these CLI PHP extensions before installation: $($missing -join ', ')."
    }
    if ($null -eq (Get-Command aspell -ErrorAction SilentlyContinue)) {
        throw 'Install GNU Aspell and its English dictionary before installing QA, or use -NoInstall to generate files only.'
    }
    return $composerCommand.Source
}

function Invoke-QaComposer {
    param([string] $Composer, [string[]] $Arguments)
    if ([System.IO.Path]::GetExtension($Composer) -in @('.bat', '.cmd')) {
        # cmd.exe strips Composer constraint carets (e.g. ^4.4) from batch
        # arguments. Invoke the sibling PHP entrypoint with native argv instead.
        $entrypoint = if ($env:QA_COMPOSER_PHAR) { $env:QA_COMPOSER_PHAR } else { Join-Path (Split-Path -Parent $Composer) 'composer.phar' }
        if (-not (Test-Path -LiteralPath $entrypoint -PathType Leaf)) {
            throw 'Composer PHP entrypoint not found. Set QA_COMPOSER_PHAR to composer.phar.'
        }
        & (Get-Command php).Source $entrypoint @Arguments
    } else {
        & $Composer @Arguments
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Composer $($Arguments[0]) failed ($LASTEXITCODE). Generated files were kept."
    }
}

function Copy-QaRuntime {
    param([string] $PackageRoot, [string] $Destination)
    $sourceRoot = Join-Path $PackageRoot 'runtime'
    if (-not (Test-Path -LiteralPath (Join-Path $sourceRoot 'qa.php'))) {
        throw 'Download or clone the entire QA repository; its runtime directory is required.'
    }
    foreach ($file in Get-ChildItem -LiteralPath $sourceRoot -Recurse -File) {
        $relative = $file.FullName.Substring($sourceRoot.Length).TrimStart([char[]] '\/')
        $destinationFile = Join-Path $Destination $relative
        $parent = Split-Path -Parent $destinationFile
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
        Copy-Item -LiteralPath $file.FullName -Destination $destinationFile -Force
    }
}

function Write-QaLaunchers {
    param([string] $Destination, [string] $RuntimePath, [switch] $ProjectRoot)
    foreach ($name in @('qa', 'qa-php')) {
        $plainArgument = if ($name -eq 'qa-php') { '--plain ' } else { '' }
        $batch = @'
@echo off
setlocal DISABLEDELAYEDEXPANSION
__QA_PUSHD__
if defined QA_PHP (
    "%QA_PHP%" "%~dp0__QA_RUNTIME__" __QA_PLAIN__%*
) else (
    php "%~dp0__QA_RUNTIME__" __QA_PLAIN__%*
)
set "qaExit=%ERRORLEVEL%"
__QA_POPD__
exit /b %qaExit%
'@
        $batch = $batch.Replace('__QA_RUNTIME__', ($RuntimePath -replace '/', '\')).Replace('__QA_PLAIN__', $plainArgument)
        $batch = $batch.Replace('__QA_PUSHD__', $(if ($ProjectRoot) { 'pushd "%~dp0"' } else { '' }))
        $batch = $batch.Replace('__QA_POPD__', $(if ($ProjectRoot) { 'popd' } else { '' }))
        Write-QaFile (Join-Path $Destination ($name + '.bat')) (($batch -replace '\r?\n', "`r`n") + "`r`n")
        $powershell = @'
$qaPhp = if ($env:QA_PHP) { $env:QA_PHP } else { 'php' }
__QA_PUSH_LOCATION__
try {
    & $qaPhp (Join-Path $PSScriptRoot '__QA_RUNTIME__') __QA_PLAIN__@args
    $qaExit = $LASTEXITCODE
} finally {
    __QA_POP_LOCATION__
}
exit $qaExit
'@
        $powershell = $powershell.Replace('__QA_RUNTIME__', $RuntimePath).Replace('__QA_PLAIN__', $plainArgument)
        $powershell = $powershell.Replace('__QA_PUSH_LOCATION__', $(if ($ProjectRoot) { 'Push-Location -LiteralPath $PSScriptRoot' } else { '' }))
        $powershell = $powershell.Replace('__QA_POP_LOCATION__', $(if ($ProjectRoot) { 'Pop-Location' } else { '# Preserve the caller''s working directory.' }))
        Write-QaFile (Join-Path $Destination ($name + '.ps1')) ($powershell + "`n")
        $shell = @'
#!/usr/bin/env sh
set -eu
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
__QA_CD__
exec "${QA_PHP:-php}" "$script_dir/__QA_RUNTIME__" __QA_PLAIN__"$@"
'@
        $shell = $shell.Replace('__QA_RUNTIME__', $RuntimePath).Replace('__QA_PLAIN__', $plainArgument)
        $shell = $shell.Replace('__QA_CD__', $(if ($ProjectRoot) { 'cd "$script_dir"' } else { '' }))
        $shellPath = Join-Path $Destination $name
        Write-QaFile $shellPath (($shell -replace '\r?\n', "`n") + "`n")
        if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
            & chmod +x $shellPath
            if ($LASTEXITCODE -ne 0) { throw "Cannot make $shellPath executable." }
        }
    }
}

function Install-QaIsolatedTools {
    param([string] $PackageRoot, [string] $Destination, [string] $Composer, [switch] $NoInstall)
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $PackageRoot 'tools/composer.json') -Destination (Join-Path $Destination 'composer.json') -Force
    if (-not $NoInstall) {
        Invoke-QaComposer $Composer -Arguments @('update', "--working-dir=$Destination", '--no-interaction', '--no-progress', '--prefer-dist')
    }
}
