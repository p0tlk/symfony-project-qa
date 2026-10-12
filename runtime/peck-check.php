<?php

declare(strict_types=1);

// Peck 0.3's CLI creates peck.json before processing --config and prefixes
// absolute config paths with the project directory. Use its checkers directly
// so qa can respect the selected config without changing the project files.
try {
    $toolsVendor = getenv('QA_TOOLS_VENDOR');
    if ($toolsVendor === false || $toolsVendor === '') {
        throw new RuntimeException('Run spelling checks through qa so its tools directory is selected.');
    }
    require $toolsVendor . '/autoload.php';
    $root = getcwd() ?: throw new RuntimeException('Cannot resolve project root.');
    $loader = new Composer\Autoload\ClassLoader(getenv('QA_PROJECT_VENDOR') ?: $root . '/vendor');
    $loader->register(true);
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $config = new Peck\Config($data['ignore']['words'] ?? [], $data['ignore']['paths'] ?? [], $data['preset'] ?? null, $data['language'] ?? null);
    // Isolate each language's dictionary cache inside this project's report.
    $cache = new Peck\Plugins\Cache($argv[3] . '/' . ($config->language ?? 'en_US'));
    $aspell = new Peck\Services\Spellcheckers\Aspell($config, $cache);
    if (getenv('QA_PLAIN') === '1') {
        require __DIR__ . '/peck-plain.php';
        $issues = qaPlainSpelling($config, $aspell, $root);
    } else {
        $kernel = new Peck\Kernel([new Peck\Checkers\FileSystemChecker($config, $aspell), new Peck\Checkers\SourceCodeChecker($config, $aspell)]);
        $issues = $kernel->handle(['directory' => $argv[2], 'onSuccess' => static function (): void {}, 'onFailure' => static function (): void {}]);
    }
    foreach ($issues as $issue) {
        echo $issue->file . ':' . $issue->line . ': Misspelling "' . $issue->misspelling->word . '"';
        if ($issue->misspelling->suggestions !== []) {
            echo ' (suggestions: ' . implode(', ', $issue->misspelling->suggestions) . ')';
        }
        echo PHP_EOL;
    }
    echo $issues === [] ? 'No misspellings found.' . PHP_EOL : count($issues) . ' misspelling(s) found.' . PHP_EOL;
    exit($issues === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Peck: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}
