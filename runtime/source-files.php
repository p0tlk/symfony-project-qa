<?php

declare(strict_types=1);

/** Plain-mode tools share the exact selected source inventory. */
function qaReadSourceManifest(): array
{
    $manifest = getenv('QA_SOURCE_MANIFEST');
    if ($manifest === false || $manifest === '') {
        return [];
    }
    $paths = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($paths) || !array_is_list($paths)) {
        throw new RuntimeException('Invalid QA source inventory.');
    }
    return $paths;
}

/** Select source without following symlinks into dependencies or generated output. */
function qaPlainFiles(array $directories, string $reportDir): array
{
    $excluded = ['vendor', 'node_modules', '.git', '.svn', '.hg', '.qa', 'var', 'cache', 'dist', '_output', '_generated'];
    $toolConfigs = ['rector.php', 'rector.php.dist', '.php-cs-fixer.php', '.php-cs-fixer.dist.php', 'composer-dependency-analyser.php'];
    $reportDir = rtrim(str_replace('\\', '/', realpath($reportDir) ?: $reportDir), '/');
    $files = [];
    foreach (array_unique($directories) as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        $directory = str_replace('\\', '/', realpath($directory) ?: $directory);
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($excluded, $toolConfigs, $reportDir): bool {
                if ($file->isLink()) {
                    return false;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (strcasecmp($path, $reportDir) === 0 || strncasecmp($path, $reportDir . '/', strlen($reportDir) + 1) === 0) {
                    return false;
                }
                if ($file->isDir()) {
                    if (in_array(strtolower($file->getFilename()), $excluded, true)) {
                        return false;
                    }
                    // A previous run may have used another report destination.
                    if (is_file($path . '/.qa-run.lock') && is_file($path . '/report.json')) {
                        $previous = json_decode((string) file_get_contents($path . '/report.json'), true);
                        if (is_array($previous) && isset($previous['project'], $previous['checks'], $previous['startedAt'])) {
                            return false;
                        }
                    }
                    return true;
                }
                return strtolower($file->getExtension()) === 'php' && !in_array($file->getFilename(), $toolConfigs, true);
            },
        );
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            $path = str_replace('\\', '/', $file->getRealPath());
            $files[$path] = $path;
        }
    }
    ksort($files);
    return array_values($files);
}
