[CmdletBinding()]
param([string] $ToolsHome = (Join-Path (Split-Path -Parent $PSScriptRoot) 'tools'))

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$packageRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $packageRoot 'installer-common.ps1')
$workRoot = Join-Path $PSScriptRoot ('work/' + [guid]::NewGuid().ToString('N'))
$plainRoot = Join-Path $workRoot 'plain project with spaces'
$composerRoot = Join-Path $workRoot 'composer project with spaces'
$globalRoot = Join-Path $workRoot 'global tools with spaces'
$originalToolsHome = $env:QA_TOOLS_HOME
$originalToolsVendor = $env:QA_TOOLS_VENDOR
$php = if ($env:QA_PHP) { $env:QA_PHP } else { (Get-Command php).Source }
$composerCommand = (Get-Command composer).Source

function Assert-Test {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Invoke-TestQa {
    param([string] $Root, [string] $Launcher, [string[]] $Arguments, [int] $ExpectedExit, [string] $ReportDirectory = 'var/qa')
    Push-Location -LiteralPath $Root
    try {
        # Use a child PowerShell: generated launchers intentionally propagate exit codes.
        & (Get-Process -Id $PID).Path -NoProfile -ExecutionPolicy Bypass -File $Launcher @Arguments *> (Join-Path $Root 'qa-test-output.txt')
        Assert-Test ($LASTEXITCODE -eq $ExpectedExit) "Unexpected QA exit $LASTEXITCODE in $Root. See qa-test-output.txt."
    } finally { Pop-Location }
    $reportPath = Join-Path $Root ($ReportDirectory + '/report.json')
    Assert-Test (Test-Path -LiteralPath $reportPath) "Missing JSON report: $reportPath"
    return (Get-Content -LiteralPath $reportPath -Raw | ConvertFrom-Json)
}

try {
    $env:QA_TOOLS_HOME = [System.IO.Path]::GetFullPath($ToolsHome)
    Remove-Item Env:QA_TOOLS_VENDOR -ErrorAction SilentlyContinue
    Assert-Test (Test-Path -LiteralPath (Join-Path $env:QA_TOOLS_HOME 'vendor/autoload.php')) 'Install QA dependencies in tools/ or pass -ToolsHome before running integration checks.'

    $calculator = @'
<?php

declare(strict_types=1);

final class Calculator
{
    public function add(int $left, int $right): int
    {
        return $left + $right;
    }
}
'@
    Write-QaFile (Join-Path $plainRoot 'classes/Calculator.php') ($calculator + "`n")
    Write-QaFile (Join-Path $plainRoot 'index.php') (@'
<?php

declare(strict_types=1);

require_once __DIR__ . '/classes/Calculator.php';

$calculator = new Calculator();
echo $calculator->add(1, 2) . PHP_EOL;
file_put_contents(__DIR__ . '/application-was-executed.txt', 'An entry script was executed');
'@ + "`n")
    Write-QaFile (Join-Path $plainRoot 'tests/bootstrap.php') ("<?php`n`ndeclare(strict_types=1);`n`nrequire_once __DIR__ . '/../classes/Calculator.php';`n")
    Write-QaFile (Join-Path $plainRoot 'tests/CalculatorTest.php') (@'
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testAddition(): void
    {
        self::assertSame(3, (new Calculator())->add(1, 2));
    }
}
'@ + "`n")
    Write-QaFile (Join-Path $plainRoot 'phpunit.xml') '<phpunit bootstrap="tests/bootstrap.php" cacheDirectory="var/cache/phpunit"><testsuites><testsuite name="Plain"><directory>tests</directory></testsuite></testsuites></phpunit>'
    foreach ($path in @('vendor/ignored.php', 'node_modules/ignored.php', 'var/cache/ignored.php', 'output/report/ignored.php')) {
        Write-QaFile (Join-Path $plainRoot $path) '<?php intentionally invalid ignored PHP'
    }
    Push-Location -LiteralPath $plainRoot
    try { & (Join-Path $packageRoot 'install-project.ps1') -NoInstall } finally { Pop-Location }
    Assert-Test (-not (Test-Path -LiteralPath (Join-Path $plainRoot 'composer.json'))) 'Plain installer created an application Composer manifest.'
    $plain = Invoke-TestQa $plainRoot (Join-Path $plainRoot 'qa-php.ps1') @('full', '--report-dir=output/report') 0 'output/report'
    Assert-Test ($plain.projectType -eq 'plain-php') 'Plain mode was not selected.'
    Assert-Test (@($plain.checks | Where-Object { $_.name -eq 'PHPUnit' -and $_.status -eq 'passed' }).Count -eq 1) 'Configured global PHPUnit did not run.'
    Assert-Test (Test-Path -LiteralPath (Join-Path $plainRoot 'output/report/phpunit.xml')) 'JUnit report was not generated.'
    Assert-Test (-not (Test-Path -LiteralPath (Join-Path $plainRoot 'application-was-executed.txt'))) 'Static checks executed the entry script.'
    Assert-Test (-not (Test-Path -LiteralPath (Join-Path $plainRoot 'vendor/autoload.php'))) 'Plain QA generated an application autoloader.'
    $cleanHash = (Get-FileHash -LiteralPath (Join-Path $plainRoot 'classes/Calculator.php')).Hash
    $plainCheck = Invoke-TestQa $plainRoot (Join-Path $plainRoot 'qa.ps1') @('check') 0
    Assert-Test ($cleanHash -eq (Get-FileHash -LiteralPath (Join-Path $plainRoot 'classes/Calculator.php')).Hash) 'Check mode modified source.'
    Assert-Test ($plainCheck.projectType -eq 'plain-php') 'Automatic plain mode failed.'

    Write-QaFile (Join-Path $composerRoot 'composer.json') '{"name":"qa-tests/composer-fixture","require":{"php":"^8.4","psr/log":"^3.0"},"require-dev":{"phpunit/phpunit":"^10.5"},"autoload":{"psr-4":{"App\\":"src/"}},"autoload-dev":{"psr-4":{"App\\Tests\\":"tests/"}}}'
    $composerCalculator = $calculator.Replace('final class', "namespace App;`n`nuse Psr\Log\NullLogger;`n`nfinal class")
    $composerCalculator = $composerCalculator.Replace('return $left + $right;', "`$logger = new NullLogger();`n        `$logger->info('addition');`n`n        return `$left + `$right;")
    Write-QaFile (Join-Path $composerRoot 'src/Calculator.php') ($composerCalculator + "`n")
    Write-QaFile (Join-Path $composerRoot 'tests/CalculatorTest.php') (@'
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testAddition(): void
    {
        self::assertSame(3, (new Calculator())->add(1, 2));
    }
}
'@ + "`n")
    Write-QaFile (Join-Path $composerRoot 'phpunit.xml') '<phpunit bootstrap="vendor/autoload.php" cacheDirectory="var/cache/phpunit"><testsuites><testsuite name="Composer"><directory>tests</directory></testsuite></testsuites></phpunit>'
    Write-QaFile (Join-Path $composerRoot 'peck.json') '{"preset":"base","ignore":{"words":["customdomain"],"paths":["vendor","var"]}}'
    $dictionaryHash = (Get-FileHash -LiteralPath (Join-Path $composerRoot 'peck.json')).Hash
    Push-Location -LiteralPath $composerRoot
    try {
        & $composerCommand install --no-interaction --no-progress --no-plugins --no-scripts *> 'composer-test-output.txt'
        Assert-Test ($LASTEXITCODE -eq 0) 'Fixture Composer installation failed.'
        & (Join-Path $packageRoot 'install-project.ps1') -NoInstall
        & (Join-Path $packageRoot 'install-project.ps1') -NoInstall -Force
    } finally { Pop-Location }
    Assert-Test ($dictionaryHash -eq (Get-FileHash -LiteralPath (Join-Path $composerRoot 'peck.json')).Hash) 'Installer overwrote the project dictionary.'
    $composerReport = Invoke-TestQa $composerRoot (Join-Path $composerRoot 'qa.ps1') @('full') 0
    Assert-Test ($composerReport.projectType -eq 'composer') 'Composer mode was not selected.'
    Assert-Test (@($composerReport.checks | Where-Object { $_.name -eq 'ShipMonk Composer dependency analyser' -and $_.status -eq 'passed' }).Count -eq 1) 'Dependency analysis did not run.'

    & (Join-Path $packageRoot 'install-global.ps1') -Destination $globalRoot -NoInstall -NoPath
    $globalReport = Invoke-TestQa $plainRoot (Join-Path $globalRoot 'bin/qa.ps1') @('check') 0
    Assert-Test ($globalReport.project -eq ($plainRoot -replace '\\', '/')) 'Global launcher changed the project directory.'

    $source = Join-Path $plainRoot 'classes/Calculator.php'
    $clean = [System.IO.File]::ReadAllText($source)
    try {
        $broken = $clean.TrimEnd().Substring(0, $clean.TrimEnd().Length - 1) + "`n    public function calcluateTotall(`$value) { return `$value; }`n}`n"
        Write-QaFile $source $broken
        $badHash = (Get-FileHash -LiteralPath $source).Hash
        $negative = Invoke-TestQa $plainRoot (Join-Path $plainRoot 'qa.ps1') @('check') 1
        foreach ($name in @('PHP CS Fixer', 'PHPCS', 'PHPStan and ShipMonk rules and dead code', 'Peck spelling')) {
            Assert-Test (@($negative.checks | Where-Object { $_.name -eq $name -and $_.status -eq 'failed' }).Count -eq 1) "Expected a failing $name check."
        }
        Assert-Test (@($negative.artifacts | Where-Object { $_.path -like 'baselines/missingType.*' }).Count -ge 1) 'Per-identifier baselines were not generated.'
        Assert-Test ($badHash -eq (Get-FileHash -LiteralPath $source).Hash) 'Failing check mode modified source.'
        Assert-Test (Test-Path -LiteralPath (Join-Path $plainRoot 'var/qa/report.html')) 'HTML report missing after failures.'
        Assert-Test (Test-Path -LiteralPath (Join-Path $plainRoot 'var/qa/summary.md')) 'Markdown report missing after failures.'
    } finally { Write-QaFile $source $clean }

    Write-Host "Integration checks passed. Fixtures and CLI logs: $workRoot" -ForegroundColor Green
} finally {
    $env:QA_TOOLS_HOME = $originalToolsHome
    $env:QA_TOOLS_VENDOR = $originalToolsVendor
}
exit 0
