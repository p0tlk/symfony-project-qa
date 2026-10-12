<#
.SYNOPSIS
Install qa and qa-php on PATH with isolated Composer dependencies.
#>
[CmdletBinding()]
param(
    [string] $Destination,
    [switch] $Force,
    [switch] $NoInstall,
    [switch] $NoPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'installer-common.ps1')
if (-not $Destination) {
    $Destination = if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'SymfonyProjectQa' } else { Join-Path ([Environment]::GetFolderPath('UserProfile')) '.local/share/symfony-project-qa' }
}
$Destination = [System.IO.Path]::GetFullPath($Destination)
if ((Test-Path -LiteralPath (Join-Path $Destination 'runtime')) -and -not $Force) {
    throw 'A QA installation already exists here. Use -Force to update its generated runtime and tools.'
}
$composerCommand = if ($NoInstall) { $null } else { Assert-QaRequirements }
Copy-QaRuntime $PSScriptRoot (Join-Path $Destination 'runtime')
$binDirectory = Join-Path $Destination 'bin'
Write-QaLaunchers $binDirectory '../runtime/qa.php'
Install-QaIsolatedTools $PSScriptRoot (Join-Path $Destination 'tools') $composerCommand -NoInstall:$NoInstall

if (-not $NoPath) {
    if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) {
        $userPath = [Environment]::GetEnvironmentVariable('PATH', 'User')
        $entries = @($userPath -split ';' | Where-Object { $_ })
        if ($entries -notcontains $binDirectory) {
            [Environment]::SetEnvironmentVariable('PATH', (($entries + $binDirectory) -join ';'), 'User')
        }
        if (@($env:PATH -split ';') -notcontains $binDirectory) { $env:PATH = $binDirectory + ';' + $env:PATH }
        Write-Host 'Open a new terminal to use qa and qa-php from any project root.'
    } else {
        Write-Host "Add $binDirectory to your shell's PATH to use qa and qa-php."
    }
}
Write-Host "Installed global QA in $Destination" -ForegroundColor Green
if ($NoInstall) { Write-Host "Files only. Install dependencies: composer install --working-dir=`"$Destination/tools`"" }
Write-Host 'Run from a project root: qa (or qa-php). Default mode: check.' -ForegroundColor Green
