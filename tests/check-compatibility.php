<?php

declare(strict_types=1);

// Exercise real syntax against all three default fixers and retain each stage.
$root = __DIR__ . '/work/compatibility project';
$reportRoot = __DIR__ . '/results/compatibility';
$tag = $argv[1] ?? 'before';
if (preg_match('/^[a-z0-9-]+$/D', $tag) !== 1) {
    throw new InvalidArgumentException('Use a simple report tag.');
}
$reportDir = $reportRoot . '/' . $tag;
foreach ([$root . '/src', $reportDir] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}
$fixtures = [
    'ArrayAlignment' => <<<'PHP'
final class ArrayAlignment
{
    /** @return array<string, int> */
    public function values(): array
    {
        return [
            'a' => 1,
            'long_key' => 2,
        ];
    }
}
PHP,
    'UnionTypes' => <<<'PHP'
final class UnionTypes
{
    public int|string $value = 1;
    public function identity(int|string $value): int|string
    {
        return $value;
    }
    public function caught(): string
    {
        try {
            return 'ok';
        } catch (\RuntimeException|\LogicException $exception) {
            return $exception->getMessage();
        }
    }
}
PHP,
    'Closures' => <<<'PHP'
final class Closures
{
    public function callback(): \Closure
    {
        return function (int $value): int { return $value + 1; };
    }
    public function emptyClass(): object
    {
        return new class {};
    }
    public function emptyClosure(): \Closure
    {
        return function (): void {};
    }
}
PHP,
    'MultiLineArguments' => <<<'PHP'
final class MultiLineArguments
{
    public function sum(
        int $first,
        int $second,
        int $third
    ): int {
        return $first + $second + $third;
    }
    public function call(): int
    {
        return $this->sum(
            1,
            2,
            3
        );
    }
}
PHP,
    'ControlFlow' => <<<'PHP'
final class ControlFlow
{
    /** @param array<int, int> $values */
    public function total(array $values, bool $enabled, bool $allowed): int
    {
        $sum = 0;
        if (
            $enabled
            && $allowed
        ) {
            foreach ($values as $key => $value) {
                $sum += $key + $value;
            }
        } else {
            return -1;
        }
        return $sum;
    }
    public function exception(): never
    {
        throw new \RuntimeException(
            'a' . 'b'
        );
    }
}
PHP,
    'Imports' => <<<'PHP'
use function strlen;
use DateTimeImmutable;
use RuntimeException;
use const PHP_VERSION;
use function count;
use ArrayObject;

final class Imports
{
    /** @param array<int, int> $values */
    public function countValues(array $values): int
    {
        return count($values) + strlen(PHP_VERSION);
    }
    public function objects(): ArrayObject
    {
        return new ArrayObject([new DateTimeImmutable()]);
    }
    public function error(): RuntimeException
    {
        return new RuntimeException('error');
    }
}
PHP,
    'Documentation' => <<<'PHP'
final class Documentation
{
    /**
     * Value.
     *
     * @var integer
     */
    public $value = 1;
    /**
     * Identity.
     * @param integer $value value
     * @return integer
     */
    public function identity($value)
    {
        return $value;
    }
}
PHP,
    'MatchExpression' => <<<'PHP'
final class MatchExpression
{
    public function name(int $value): string
    {
        return match ($value) {
            1 => 'first',
            1000 => 'second',
            default => 'other',
        };
    }
}
PHP,
    'EmptyContract' => 'interface EmptyContract {}',
    'EmptyTrait' => 'trait EmptyTrait {}',
    'EmptyBodies' => <<<'PHP'
final class EmptyBodies
{
    public function empty(): void {}
}
PHP,
    'PromotedProperties' => <<<'PHP'
final class PromotedProperties
{
    public function __construct(
        public readonly int|string $value,
        private string $label = 'label',
    ) {}
    public function label(): string
    {
        return $this->label;
    }
}
PHP,
    'FirstContract' => 'interface FirstContract {}',
    'SecondContract' => 'interface SecondContract {}',
    'ModernSyntax' => <<<'PHP'
final class ModernSyntax
{
    public function accept((FirstContract&SecondContract)|null $value): (FirstContract&SecondContract)|null
    {
        return $value;
    }
    public function factory(): object
    {
        return new class extends \ArrayObject {};
    }
}
PHP,
    'Operators' => <<<'PHP'
final class Operators
{
    public function compare(int $first, int $second): bool
    {
        return ($first > 0 && $second > 0) || $first === $second;
    }
    /**
     * @param array<int, int> $items
     * @return array<int, int>
     */
    public function doubled(array $items): array
    {
        return array_map(fn (int $value): int => $value * 2, $items);
    }
}
PHP,
];
foreach ($fixtures as $name => $body) {
    file_put_contents($root . '/src/' . $name . '.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace CollisionTest;\n\n" . $body . "\n");
}
file_put_contents($root . '/composer.json', json_encode([
    'name' => 'local/qa-compatibility-test',
    'description' => 'Disposable fixer compatibility corpus',
    'license' => 'MIT',
    'require' => ['php' => '^8.4'],
    'autoload' => ['psr-4' => ['CollisionTest\\' => 'src/']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$php = getenv('QA_PHP') ?: PHP_BINARY;
$toolsHome = getenv('QA_TOOLS_HOME') ?: dirname(__DIR__) . '/tools';
$toolsVendor = getenv('QA_TOOLS_VENDOR') ?: $toolsHome . '/vendor';
$tools = str_replace('\\', '/', $toolsVendor) . '/bin';
$defaults = dirname(__DIR__) . '/runtime/defaults';
$env = getenv();
$env['QA_TARGET_PATH'] = 'src';
$env['NO_COLOR'] = '1';
$env['QA_PLAIN'] = '0';
unset($env['QA_SOURCE_MANIFEST']);
$env['PATH'] = dirname($php) . PATH_SEPARATOR . ($env['PATH'] ?? $env['Path'] ?? '');
unset($env['Path']);
$invoke = static function (string $name, array $command) use ($root, $reportDir, $env): int {
    $log = $reportDir . '/' . $name . '.log';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $root, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $name);
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    echo $name . ': exit ' . $exitCode . "\n";
    return $exitCode;
};
$snapshot = static function (string $name) use ($root, $reportDir): array {
    $destination = $reportDir . '/snapshots/' . $name;
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    $hashes = [];
    foreach (glob($root . '/src/*.php') ?: [] as $file) {
        $hashes[basename($file)] = hash_file('sha256', $file);
        copy($file, $destination . '/' . basename($file));
    }
    return $hashes;
};
// Rector's Composer-based sets require installed package metadata, even for an
// application with no third-party runtime dependencies. Locate Composer's PHP
// entrypoint so process launch remains portable and never invokes a batch shell.
$composer = getenv('QA_COMPOSER_PHAR') ?: null;
foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: getenv('Path') ?: '') as $directory) {
    if ($composer !== null) {
        break;
    }
    foreach (PHP_OS_FAMILY === 'Windows' ? ['composer.phar'] : ['composer.phar', 'composer'] as $entry) {
        $candidate = rtrim($directory, '/\\') . '/' . $entry;
        if (is_file($candidate)) {
            $composer = $candidate;
            break;
        }
    }
}
if ($composer === null || !is_file($composer)) {
    throw new RuntimeException('Composer PHP entrypoint not found on PATH. Set QA_COMPOSER_PHAR to composer.phar.');
}
if ($invoke('autoload', [$php, $composer, 'install', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts']) !== 0) {
    throw new RuntimeException('Compatibility fixture dependency metadata could not be generated.');
}
$stages = [];
$previous = $snapshot('initial');
for ($pass = 1; $pass <= 3; ++$pass) {
    $commands = [
        'rector' => [$php, $tools . '/rector', 'process', '--no-ansi', '--config=' . $defaults . '/rector.php'],
        'phpcbf' => [$php, $tools . '/phpcbf', '--no-colors', '--standard=' . $defaults . '/phpcs.xml', 'src'],
        'cs-fixer' => [$php, $tools . '/php-cs-fixer', 'fix', '--no-ansi', '--show-progress=none', '--config=' . $defaults . '/php-cs-fixer.php'],
    ];
    foreach ($commands as $tool => $command) {
        $name = 'pass-' . $pass . '-' . $tool;
        $code = $invoke($name, $command);
        $current = $snapshot($name);
        $changed = array_keys(array_diff_assoc($current, $previous));
        $stages[] = ['pass' => $pass, 'tool' => $tool, 'exitCode' => $code, 'changedFiles' => $changed];
        echo '  changed: ' . ($changed === [] ? 'none' : implode(', ', $changed)) . "\n";
        $previous = $current;
    }
}
$phpcsCode = $invoke('phpcs-final', [$php, $tools . '/phpcs', '-q', '--no-colors', '--no-cache', '--standard=' . $defaults . '/phpcs.xml', '--report=json', '--report-file=' . $reportDir . '/phpcs-final.json', 'src']);
$fixerCode = $invoke('cs-fixer-final', [$php, $tools . '/php-cs-fixer', 'fix', '--dry-run', '--diff', '--no-ansi', '--show-progress=none', '--config=' . $defaults . '/php-cs-fixer.php']);
$rectorCode = $invoke('rector-final', [$php, $tools . '/rector', 'process', '--dry-run', '--no-ansi', '--config=' . $defaults . '/rector.php']);
$report = ['fixtureCount' => count($fixtures), 'stages' => $stages, 'finalChecks' => ['phpcs' => $phpcsCode, 'cs-fixer' => $fixerCode, 'rector' => $rectorCode]];
file_put_contents($reportDir . '/compatibility-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Report: ' . $reportDir . '/compatibility-report.json' . "\n";
$unstable = array_filter($stages, static fn(array $stage): bool => $stage['pass'] === 3 && $stage['changedFiles'] !== []);
exit($unstable === [] && $phpcsCode === 0 && $fixerCode === 0 && $rectorCode === 0 ? 0 : 1);
