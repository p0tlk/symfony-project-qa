<?php

declare(strict_types=1);

require __DIR__ . '/report.php';
require __DIR__ . '/phpstan-config.php';
require __DIR__ . '/source-files.php';

function qaHelp(): void
{
    echo <<<'HELP'
Symfony / PHP quality checks

Run from a PHP project root (Composer is optional):
  qa                       Run all checks and save reports (default: check)
  qa check                 Same as qa
  qa fix                   Run Rector, PHPCBF and PHP CS Fixer changes
  qa full                  Fix first, then run all checks
  qa-php                   Plain PHP checks without Composer or a framework
  qa --plain               Explicit plain PHP mode; auto-detected without Composer
  qa --target=app          Source directory (Composer: src/app; plain PHP: .)
  qa --with-tests          Require PHPUnit; otherwise run it when configured
  qa --skip-tests          Skip PHPUnit
  qa --skip-peck           Skip spelling checks
  qa --report-dir=var/qa    Override the report directory
  qa --timeout=900         Maximum seconds per tool; 0 disables the limit
  qa --help                Show this help

Reports: var/qa/report.html, report.json, summary.md and individual tool logs.
Every check runs even when an earlier check fails. Exit: 0 passed, 1 failed,
2 invalid arguments/setup. Project configs take precedence over defaults.
PHPStan always includes ShipMonk rules and dead-code detection, analysing source
and tests. It exports a baseline split by identifier into the report directory;
that export is never included in the project's analysis configuration.
Plain mode scans PHP files including root scripts, excludes dependencies/caches/
reports, uses generic Rector rules and skips Composer dependency analysis.

Environment: QA_TOOLS_HOME (optional tools installation), QA_PHP (PHP executable).
HELP;
    echo PHP_EOL;
}

function qaAbsolute(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    return preg_match('~^(?:[A-Za-z]:/|/)~', $path) === 1 ? $path : $root . '/' . $path;
}

function qaFirstFile(string $root, array $names): ?string
{
    foreach ($names as $name) {
        if (is_file($root . '/' . $name)) {
            return $root . '/' . $name;
        }
    }
    return null;
}

function qaMakeDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory: ' . $path);
    }
}

function qaWriteFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write file: ' . $path);
    }
}

/** Include every existing Composer autoload path, including development code. */
function qaAutoloadPaths(array $composer, string $root): array
{
    $paths = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $type) {
            foreach (($composer[$section][$type] ?? []) as $entries) {
                foreach ((array) $entries as $entry) {
                    if (!is_string($entry) || $entry === '') {
                        continue;
                    }
                    $absolute = qaAbsolute($entry, $root);
                    $matches = strpbrk($absolute, '*?') !== false ? (glob($absolute) ?: []) : [$absolute];
                    foreach ($matches as $match) {
                        $real = realpath($match);
                        if ($real === false || (!is_dir($real) && !str_ends_with($real, '.php'))) {
                            continue;
                        }
                        $real = str_replace('\\', '/', $real);
                        // Root mappings would include vendor, caches and report configs.
                        if (strcasecmp(rtrim($real, '/'), rtrim($root, '/')) === 0) {
                            continue;
                        }
                        $paths[] = $real;
                    }
                }
            }
        }
    }
    return array_values(array_unique($paths));
}

/** Discover project-local, isolated plain-PHP, or globally installed tools. */
function qaToolsVendor(string $root, string $projectVendor): string
{
    $home = getenv('QA_TOOLS_HOME');
    $vendor = getenv('QA_TOOLS_VENDOR');
    if ($home !== false && $home !== '') {
        $candidates = [qaAbsolute($home, $root) . '/vendor'];
    } elseif ($vendor !== false && $vendor !== '') {
        $candidates = [qaAbsolute($vendor, $root)];
    } else {
        $candidates = [dirname(__DIR__) . '/tools/vendor', $root . '/.qa/tools/vendor', $projectVendor];
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/autoload.php') && is_file($candidate . '/bin/phpstan')) {
            return str_replace('\\', '/', realpath($candidate) ?: $candidate);
        }
    }
    throw new RuntimeException('QA dependencies are missing. Run the installer, composer install, or set QA_TOOLS_HOME to an installed tools directory.');
}

function qaPsalmConfig(string $root, string $vendorDir, string $reportDir, array $paths, bool $symfony): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = '<?xml version="1.0"?>' . "\n"
        . '<psalm errorLevel="3" resolveFromConfigFile="false" ensureOverrideAttribute="false" findUnusedCode="false"'
        . ' cacheDirectory="' . $escape($reportDir . '/cache/psalm') . '"'
        . ' xmlns="https://getpsalm.org/schema/config">' . "\n    <projectFiles>\n";
    foreach ($paths as $path) {
        $element = is_dir($path) ? 'directory' : 'file';
        $xml .= '        <' . $element . ' name="' . $escape($path) . '" />' . "\n";
    }
    if (is_dir($vendorDir)) {
        $xml .= '        <ignoreFiles><directory name="' . $escape($vendorDir) . '" /></ignoreFiles>' . "\n";
    }
    $xml .= "    </projectFiles>\n";
    if ($symfony) {
        $xml .= '    <plugins><pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" /></plugins>' . "\n";
    }
    $xml .= "</psalm>\n";
    $file = $reportDir . '/config/psalm.xml';
    qaWriteFile($file, $xml);
    return $file;
}

function qaCheck(array &$report, string $name, array $command, string $reportDir, string $root, array $environment, int $timeout, array $successCodes = [0], ?string $skip = null): array
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    $log = $slug . '.log';
    $entry = ['name' => $name, 'status' => 'skipped', 'exitCode' => null, 'duration' => 0.0, 'command' => $command, 'log' => $log, 'reason' => $skip];
    echo PHP_EOL . '==> ' . $name . PHP_EOL;
    if ($skip !== null) {
        echo 'SKIPPED: ' . $skip . PHP_EOL;
        qaWriteFile($reportDir . '/' . $log, $skip . PHP_EOL);
        $report['checks'][] = $entry;
        return $entry;
    }
    $start = microtime(true);
    $process = null;
    $logReader = null;
    $error = null;
    $exitCode = 1;
    try {
        qaWriteFile($reportDir . '/' . $log, '');
        if (!is_file($command[0]) || (isset($command[1]) && !is_file($command[1]))) {
            throw new RuntimeException('Required tool is missing: ' . ($command[1] ?? $command[0]));
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $reportDir . '/' . $log, 'w'], 2 => ['redirect', 1]], $pipes, $root, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start tool.');
        }
        fclose($pipes[0]);
        $logReader = fopen($reportDir . '/' . $log, 'r');
        do {
            if (is_resource($logReader)) {
                $chunk = stream_get_contents($logReader);
                if ($chunk !== false && $chunk !== '') {
                    echo $chunk;
                }
            }
            $state = proc_get_status($process);
            if (!$state['running']) {
                $exitCode = $state['exitcode'];
                break;
            }
            if ($timeout > 0 && microtime(true) - $start > $timeout) {
                // PHP's Windows terminate only stops the parent; stop worker trees too.
                if (PHP_OS_FAMILY === 'Windows') {
                    $killer = proc_open(['C:/Windows/System32/taskkill.exe', '/PID', (string) $state['pid'], '/T', '/F'], [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $killPipes);
                    if (is_resource($killer)) {
                        fclose($killPipes[0]);
                        proc_close($killer);
                    }
                }
                proc_terminate($process);
                $error = 'Tool exceeded timeout of ' . $timeout . ' seconds.';
                $exitCode = 124;
                break;
            }
            usleep(200000);
        } while (true);
        $closedCode = proc_close($process);
        $process = null;
        if ($exitCode < 0 && $closedCode >= 0) {
            $exitCode = $closedCode;
        }
        if (is_resource($logReader)) {
            echo stream_get_contents($logReader) ?: '';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    } finally {
        if (is_resource($logReader)) {
            fclose($logReader);
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    if ($error !== null) {
        file_put_contents($reportDir . '/' . $log, "\n" . $error . "\n", FILE_APPEND);
        echo $error . PHP_EOL;
    }
    $entry['exitCode'] = $exitCode;
    $entry['duration'] = round(microtime(true) - $start, 3);
    $entry['status'] = $error === null && in_array($exitCode, $successCodes, true) ? 'passed' : 'failed';
    $entry['reason'] = $error;
    $report['checks'][] = $entry;
    echo strtoupper($entry['status']) . ' (exit ' . $exitCode . ', ' . $entry['duration'] . 's)' . PHP_EOL;
    return $entry;
}

function qaSetupFailure(array &$report, string $name, Throwable $error, string $reportDir): void
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    $log = $slug . '.log';
    $reason = $error->getMessage();
    qaWriteFile($reportDir . '/' . $log, $reason . PHP_EOL);
    $report['checks'][] = ['name' => $name, 'status' => 'failed', 'exitCode' => 2, 'duration' => 0.0, 'command' => [], 'log' => $log, 'reason' => $reason];
    echo PHP_EOL . '==> ' . $name . PHP_EOL . $reason . PHP_EOL . 'FAILED (exit 2, setup)' . PHP_EOL;
}

function qaMain(array $arguments): int
{
    $options = ['mode' => 'check', 'target' => null, 'report-dir' => 'var/qa', 'timeout' => 900, 'with-tests' => false, 'skip-tests' => false, 'skip-peck' => false, 'plain' => false];
    for ($i = 0; $i < count($arguments); ++$i) {
        $argument = $arguments[$i];
        if (in_array($argument, ['--help', '-h', '/?'], true)) {
            qaHelp();
            return 0;
        }
        if (in_array($argument, ['check', 'fix', 'full'], true)) {
            $options['mode'] = $argument;
            continue;
        }
        // Support the previous PowerShell runner's parameter spelling as well.
        $aliases = ['-WithTests' => '--with-tests', '-SkipTests' => '--skip-tests', '-SkipPeck' => '--skip-peck', '-Target' => '--target', '-ReportDir' => '--report-dir', '-Timeout' => '--timeout', '-Mode' => '--mode'];
        $argument = $aliases[$argument] ?? $argument;
        if (in_array($argument, ['--with-tests', '--skip-tests', '--skip-peck', '--plain'], true)) {
            $options[substr($argument, 2)] = true;
            continue;
        }
        if (preg_match('/^--(target|report-dir|timeout|mode)(?:=(.*))?$/', $argument, $match) === 1) {
            $value = $match[2] ?? ($arguments[++$i] ?? null);
            if ($value === null || $value === '' || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Missing value for --' . $match[1]);
            }
            $options[$match[1]] = $value;
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument . '. Run qa --help.');
    }
    if (!in_array($options['mode'], ['check', 'fix', 'full'], true)) {
        throw new InvalidArgumentException('Mode must be check, fix or full.');
    }
    if (!ctype_digit((string) $options['timeout'])) {
        throw new InvalidArgumentException('--timeout must be a nonnegative integer.');
    }
    if ($options['with-tests'] && $options['skip-tests']) {
        throw new InvalidArgumentException('--with-tests and --skip-tests cannot be combined.');
    }
    $root = str_replace('\\', '/', getcwd() ?: throw new RuntimeException('Cannot determine the project root.'));
    $settingsFile = $root . '/.qa/settings.json';
    $settings = is_file($settingsFile) ? json_decode((string) file_get_contents($settingsFile), true, 512, JSON_THROW_ON_ERROR) : [];
    if (!is_array($settings) || (isset($settings['target']) && !is_string($settings['target'])) || (isset($settings['plain']) && !is_bool($settings['plain']))) {
        throw new RuntimeException('Invalid .qa/settings.json: target must be a string and plain must be a boolean.');
    }
    $hasComposer = is_file($root . '/composer.json');
    $plain = $options['plain'] || ($settings['plain'] ?? false) || !$hasComposer;
    $composer = $hasComposer ? json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    $php = getenv('QA_PHP') ?: PHP_BINARY;
    $vendorDir = qaAbsolute($composer['config']['vendor-dir'] ?? 'vendor', $root);
    $toolsVendor = qaToolsVendor($root, $vendorDir);
    if (!$plain && !is_file($vendorDir . '/autoload.php')) {
        throw new RuntimeException('Project dependencies are missing. Run composer install first.');
    }
    $paths = $plain ? [] : qaAutoloadPaths($composer, $root);
    $target = $options['target'] ?? ($settings['target'] ?? ($plain ? '.' : (is_dir($root . '/src') ? 'src' : (is_dir($root . '/app') ? 'app' : null))));
    if ($target === null) {
        $target = array_values(array_filter($paths, 'is_dir'))[0] ?? null;
    }
    if ($target === null || !is_dir(qaAbsolute($target, $root))) {
        throw new RuntimeException('Source directory not found. Use qa --target=path/to/source.');
    }
    $targetAbsolute = str_replace('\\', '/', realpath(qaAbsolute($target, $root)) ?: qaAbsolute($target, $root));
    if (!$plain && strcasecmp(rtrim($targetAbsolute, '/'), rtrim($root, '/')) === 0) {
        throw new RuntimeException('Choose a source directory inside the project; --target=. would also scan vendor and reports.');
    }
    if (strcasecmp(rtrim($targetAbsolute, '/'), rtrim($root, '/')) === 0) {
        $target = '.';
    } elseif (strncasecmp($targetAbsolute . '/', rtrim($root, '/') . '/', strlen(rtrim($root, '/') . '/')) === 0) {
        $target = substr($targetAbsolute, strlen(rtrim($root, '/')) + 1);
    }
    $paths = array_values(array_unique([$targetAbsolute, ...$paths]));
    if (is_dir($root . '/tests')) {
        $paths = array_values(array_unique([...$paths, $root . '/tests']));
    }
    $reportDir = rtrim(qaAbsolute((string) $options['report-dir'], $root), '/');
    $sourceFiles = $plain ? qaPlainFiles([$targetAbsolute], $reportDir) : [];
    if ($plain) {
        if ($sourceFiles === []) {
            throw new RuntimeException('No PHP source files found. Use qa-php --target=path/to/source.');
        }
        $paths = qaPlainFiles([$targetAbsolute, $root . '/tests'], $reportDir);
    }
    qaMakeDirectory($reportDir);
    $reportDir = str_replace('\\', '/', realpath($reportDir) ?: $reportDir);
    $lock = fopen($reportDir . '/.qa-run.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another qa run is already writing this report directory.');
    }
    qaMakeDirectory($reportDir . '/config');
    qaMakeDirectory($reportDir . '/baselines');
    $report = ['project' => $root, 'mode' => $options['mode'], 'projectType' => $plain ? 'plain-php' : 'composer', 'startedAt' => date(DATE_ATOM), 'finishedAt' => '', 'status' => 'failed', 'checks' => []];
    $artifacts = [];
    try {
        // Remove only files explicitly recorded as this project's generated artifacts.
        // Never clear a user's report directory or arbitrary baseline files.
        $previousReport = is_file($reportDir . '/report.json') ? json_decode((string) file_get_contents($reportDir . '/report.json'), true) : null;
        if (is_array($previousReport) && strcasecmp($previousReport['project'] ?? '', $root) === 0) {
            foreach (($previousReport['artifacts'] ?? []) as $artifact) {
                $relative = $artifact['path'] ?? '';
                if (!is_string($relative) || preg_match('~^(?:phpunit\.xml|baselines/[a-zA-Z0-9_.-]+\.neon)$~D', $relative) !== 1) {
                    continue;
                }
                $path = $reportDir . '/' . $relative;
                $expectedParent = str_starts_with($relative, 'baselines/') ? $reportDir . '/baselines' : $reportDir;
                $resolvedParent = realpath(dirname($path));
                if ($resolvedParent !== false && strcasecmp(str_replace('\\', '/', $resolvedParent), $expectedParent) === 0 && is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Cannot replace old generated artifact: ' . $path);
                }
            }
        }
        $environment = getenv();
        $environment['QA_TARGET_PATH'] = $target;
        $environment['QA_TOOLS_VENDOR'] = $toolsVendor;
        $environment['QA_PROJECT_VENDOR'] = $vendorDir;
        $environment['QA_PLAIN'] = $plain ? '1' : '0';
        $sourceList = null;
        if ($plain) {
            $sourceManifest = $reportDir . '/config/source-files.json';
            $sourceList = $reportDir . '/config/source-files.txt';
            qaWriteFile($sourceManifest, json_encode($sourceFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            qaWriteFile($sourceList, implode("\n", $sourceFiles) . "\n");
            $environment['QA_SOURCE_MANIFEST'] = $sourceManifest;
        } else {
            unset($environment['QA_SOURCE_MANIFEST']);
        }
        $environment['NO_COLOR'] = '1';
        $environment['PATH'] = dirname($php) . PATH_SEPARATOR . $toolsVendor . '/bin' . PATH_SEPARATOR . ($environment['PATH'] ?? $environment['Path'] ?? '');
        // Keep executable selection consistent for subprocesses on Windows.
        unset($environment['Path']);
        $binary = static fn(string $name): array => [$php, $toolsVendor . '/bin/' . $name];
        $run = static function (string $name, array $command, array $success = [0], ?string $skip = null) use (&$report, $reportDir, $root, $environment, $options): array {
            return qaCheck($report, $name, $command, $reportDir, $root, $environment, (int) $options['timeout'], $success, $skip);
        };
        $fixer = qaFirstFile($root, ['.qa/php-cs-fixer.php', '.php-cs-fixer.php', '.php-cs-fixer.dist.php']) ?? __DIR__ . '/defaults/php-cs-fixer.php';
        $rector = qaFirstFile($root, ['.qa/rector.php', 'rector.php', 'rector.php.dist']) ?? __DIR__ . '/defaults/rector.php';
        $phpcs = qaFirstFile($root, ['.qa/phpcs.xml', '.phpcs.xml', '.phpcs.xml.dist', 'phpcs.xml', 'phpcs.xml.dist']) ?? __DIR__ . '/defaults/' . ($plain ? 'phpcs-plain.xml' : 'phpcs.xml');
        $peck = qaFirstFile($root, ['peck.json', '.qa/peck.json']) ?? __DIR__ . '/defaults/peck.json';
        $phpcsPaths = $plain ? ['--file-list=' . $sourceList] : [$targetAbsolute];
        echo 'QA ' . $options['mode'] . ': ' . $root . PHP_EOL . 'Reports: ' . $reportDir . PHP_EOL;
        if ($plain) {
            echo 'Project type: plain PHP (' . count($sourceFiles) . ' source files)' . PHP_EOL;
        }
        if (in_array($options['mode'], ['fix', 'full'], true)) {
            $run('Rector fix', [...$binary('rector'), 'process', '--no-ansi', '--config=' . $rector]);
            // PHPCBF 1 = all errors fixed; 2 = some remain and must fail fix mode.
            $run('PHPCBF fix', [...$binary('phpcbf'), '--no-colors', '--standard=' . $phpcs, ...$phpcsPaths], [0, 1]);
            $run('PHP CS Fixer fix', [...$binary('php-cs-fixer'), 'fix', '--no-ansi', '--show-progress=none', '--config=' . $fixer]);
        }
        if (in_array($options['mode'], ['check', 'full'], true)) {
            $run('PHP CS Fixer', [...$binary('php-cs-fixer'), 'fix', '--dry-run', '--diff', '--no-ansi', '--show-progress=none', '--config=' . $fixer]);
            $run('PHPCS', [...$binary('phpcs'), '--no-colors', '--standard=' . $phpcs, '--no-cache', ...$phpcsPaths]);
            $run('Rector', [...$binary('rector'), 'process', '--dry-run', '--no-ansi', '--config=' . $rector]);
            try {
                $projectStan = qaFirstFile($root, ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon']);
                $stanConfig = qaPhpstanConfig($root, $vendorDir, $toolsVendor, $reportDir, $paths, $projectStan, $plain);
                $stan = $run('PHPStan and ShipMonk rules and dead code', [...$binary('phpstan'), 'analyse', '--configuration=' . $stanConfig, '--no-ansi', '--no-progress', '--memory-limit=1G', '--error-format=table', '-v']);
                $baselineFile = $reportDir . '/baselines/_loader.neon';
                // A completed analysis returns 0 or 1. Crashes/config errors return other codes.
                $baseline = $run('PHPStan baseline export', [...$binary('phpstan'), 'analyse', '--configuration=' . $stanConfig, '--no-ansi', '--no-progress', '--memory-limit=1G', '--generate-baseline=' . $baselineFile, '--allow-empty-baseline'], [0], in_array($stan['exitCode'], [0, 1], true) ? null : 'PHPStan analysis could not complete; baseline export is unavailable.');
                $split = $run('ShipMonk baseline per identifier', [...$binary('split-phpstan-baseline'), $baselineFile], [0], $baseline['status'] === 'passed' ? null : 'PHPStan baseline export did not succeed.');
                if ($split['status'] === 'passed') {
                    foreach (glob($reportDir . '/baselines/*.neon') ?: [] as $file) {
                        $artifacts[] = ['name' => 'PHPStan baseline: ' . basename($file), 'path' => 'baselines/' . basename($file)];
                    }
                }
            } catch (Throwable $exception) {
                qaSetupFailure($report, 'PHPStan configuration', $exception, $reportDir);
            }
            try {
                $requirements = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
                $psalm = qaFirstFile($root, ['psalm.xml', 'psalm.xml.dist', '.qa/psalm.xml']) ?? qaPsalmConfig($root, $vendorDir, $reportDir, $paths, !$plain && isset($requirements['symfony/framework-bundle']));
                $run('Psalm', [...$binary('psalm'), '--config=' . $psalm, '--no-progress', '--monochrome', '--threads=1']);
            } catch (Throwable $exception) {
                qaSetupFailure($report, 'Psalm configuration', $exception, $reportDir);
            }
            $run('ShipMonk Composer dependency analyser', [...$binary('composer-dependency-analyser'), '--composer-json=' . $root . '/composer.json'], [0], $plain ? 'Not applicable in plain PHP mode: no Composer dependencies to analyse.' : null);
            $testConfig = qaFirstFile($root, ['phpunit.xml', 'phpunit.xml.dist', '.phpunit.xml', '.phpunit.xml.dist']);
            $testBinary = qaFirstFile($root, ['bin/phpunit']);
            $testCommand = $testBinary !== null ? [$php, $testBinary] : (is_file($vendorDir . '/bin/phpunit') ? [$php, $vendorDir . '/bin/phpunit'] : $binary('phpunit'));
            if ($testConfig !== null) {
                $testCommand[] = '--configuration=' . $testConfig;
            }
            $testCommand[] = '--colors=never';
            $testCommand[] = '--log-junit=' . $reportDir . '/phpunit.xml';
            $testSkip = $options['skip-tests'] ? 'Skipped by --skip-tests.' : (!$options['with-tests'] && $testConfig === null && $testBinary === null ? 'No PHPUnit configuration or bin/phpunit. Use --with-tests to require tests.' : null);
            $test = $run('PHPUnit', $testCommand, [0], $testSkip);
            if ($test['status'] !== 'skipped' && is_file($reportDir . '/phpunit.xml')) {
                $artifacts[] = ['name' => 'PHPUnit JUnit results', 'path' => 'phpunit.xml'];
            }
            $run('Peck spelling', [$php, __DIR__ . '/peck-check.php', $peck, $targetAbsolute, $reportDir . '/cache/peck'], [0], $options['skip-peck'] ? 'Skipped by --skip-peck.' : null);
        }
    } catch (Throwable $exception) {
        $report['checks'][] = ['name' => 'QA configuration', 'status' => 'failed', 'exitCode' => 2, 'duration' => 0.0, 'command' => [], 'log' => 'qa-configuration.log', 'reason' => $exception->getMessage()];
        qaWriteFile($reportDir . '/qa-configuration.log', $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    } finally {
        $report['finishedAt'] = date(DATE_ATOM);
        $report['artifacts'] = $artifacts;
        $report['status'] = in_array('failed', array_column($report['checks'], 'status'), true) ? 'failed' : 'passed';
        qaWriteReports($reportDir, $report);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $statuses = array_count_values(array_column($report['checks'], 'status'));
    echo PHP_EOL . 'QA ' . strtoupper($report['status']) . ': ' . ($statuses['passed'] ?? 0) . ' passed, ' . ($statuses['failed'] ?? 0) . ' failed, ' . ($statuses['skipped'] ?? 0) . ' skipped.' . PHP_EOL;
    echo 'Open report: ' . $reportDir . '/report.html' . PHP_EOL;
    return $report['status'] === 'passed' ? 0 : 1;
}

try {
    exit(qaMain(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, 'QA: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}
