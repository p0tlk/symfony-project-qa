<?php
declare(strict_types=1);

/**
 * Compose a temporary PHPStan config without changing the project's config or baselines.
 * $vendorDir is the project vendor directory; $toolsVendor holds the QA dependencies.
 */
function qaPhpstanConfig(
    string $root,
    string $vendorDir,
    string $toolsVendor,
    string $reportDir,
    array $analysisPaths,
    ?string $projectConfig,
    bool $plain = false,
): string {
    $root = qaPhpstanPath($root);
    $vendorDir = qaPhpstanPath($vendorDir);
    $globalVendor = qaPhpstanPath($toolsVendor);
    $reportDir = qaPhpstanPath($reportDir);

    // PHPStan loads this same tools autoloader before constructing extension services.
    // Loading it here gives the config generator the official NEON parser/encoder.
    require_once $globalVendor . '/autoload.php';

    $seen = [];
    $projectParameters = [];
    $includedPaths = [];
    if ($projectConfig !== null) {
        $projectConfig = qaPhpstanAbsolutePath($projectConfig, $root);
        qaPhpstanReadConfig($projectConfig, $root, $seen, $includedPaths, $projectParameters);
    }

    $registered = '';
    $installerConfig = $vendorDir . '/phpstan/extension-installer/src/GeneratedConfig.php';
    if (is_file($installerConfig)) {
        $registered = (string) file_get_contents($installerConfig);
    }

    $includes = [];
    foreach (['dead-code-detector', 'phpstan-rules'] as $package) {
        $packageName = 'shipmonk/' . $package;
        $alreadyIncluded = str_contains($registered, "'" . $packageName . "'")
            || str_contains($registered, '"' . $packageName . '"');
        foreach ($includedPaths as $includedPath) {
            if (str_contains(strtolower(qaPhpstanPath($includedPath)), $packageName . '/rules.neon')) {
                $alreadyIncluded = true;
            }
        }
        if (!$alreadyIncluded) {
            $rules = $globalVendor . '/' . $packageName . '/rules.neon';
            if (!is_file($rules)) {
                throw new RuntimeException('Missing QA PHPStan extension: ' . $rules);
            }
            $includes[] = $rules;
        }
    }
    if ($projectConfig !== null) {
        $includes[] = $projectConfig;
    }

    $paths = [];
    foreach ($analysisPaths as $path) {
        $path = qaPhpstanAbsolutePath((string) $path, $root);
        if (file_exists($path)) {
            $paths[$path] = $path;
        }
    }
    if (!$plain && is_dir($root . '/tests')) {
        $paths[$root . '/tests'] = $root . '/tests';
    }

    $packages = $plain ? [] : qaPhpstanProjectPackages($root, $vendorDir);
    $has = static fn (string $package): bool => isset($packages[$package]);
    $symfony = count(array_filter(array_keys($packages), static fn (string $name): bool => str_starts_with($name, 'symfony/'))) > 0;
    $providers = [
        'symfony' => ['enabled' => $symfony],
        'twig' => ['enabled' => $has('twig/twig') || $has('symfony/framework-bundle') || $has('symfony/twig-bridge')],
        'doctrine' => ['enabled' => $has('doctrine/orm') || $has('doctrine/event-manager') || $has('doctrine/doctrine-bundle')],
        'phpunit' => ['enabled' => $has('phpunit/phpunit') || $has('symfony/phpunit-bridge') || ($plain && (is_file($root . '/phpunit.xml') || is_file($root . '/phpunit.xml.dist')))],
        'phpbench' => ['enabled' => $has('phpbench/phpbench')],
        'behat' => ['enabled' => $has('behat/behat')],
        'phpat' => ['enabled' => $has('phpat/phpat')],
        'eloquent' => ['enabled' => $has('illuminate/database') || $has('laravel/framework')],
        'laravel' => ['enabled' => $has('laravel/framework')],
        'blade' => ['enabled' => $has('laravel/framework')],
        'nette' => ['enabled' => $has('nette/application') || $has('nette/component-model') || $has('nette/utils')],
        'netteTester' => ['enabled' => $has('nette/tester')],
        // Two Composer autoloaders prevent autodetection of the project manifest.
        'composer' => $plain ? ['enabled' => false] : ['composerJsonPath' => $root . '/composer.json'],
    ];
    if ($symfony && is_dir($root . '/config')) {
        $providers['symfony']['configDir'] = $root . '/config';
    }
    if ($symfony) {
        $containerXmlPaths = qaPhpstanSymfonyContainers($root);
        if ($containerXmlPaths !== []) {
            $providers['symfony']['containerXmlPaths'] = $containerXmlPaths;
        }
    }

    // Keep intentional project provider settings. The defaults above compensate for
    // Composer's InstalledVersions seeing both project and global tool packages.
    $projectProviders = $projectParameters['shipmonkDeadCode']['usageProviders'] ?? [];
    foreach ($providers as $provider => &$options) {
        if (is_array($projectProviders[$provider] ?? null)) {
            foreach (array_keys($projectProviders[$provider]) as $option) {
                unset($options[$option]);
            }
        }
    }
    unset($options);
    $providers = array_filter($providers, static fn (array $options): bool => $options !== []);
    $parameters = [
        'paths!' => array_values($paths),
        'tmpDir' => $reportDir . '/cache/phpstan',
        'shipmonkDeadCode' => ['usageProviders' => $providers],
    ];
    if ($projectConfig === null) {
        $parameters['level'] = 8;
    }
    if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
        throw new RuntimeException('Cannot create QA report directory: ' . $reportDir);
    }
    $configFile = $reportDir . '/phpstan.neon';
    $config = ['includes' => $includes, 'parameters' => $parameters];
    if (file_put_contents($configFile, "# Generated by qa; project configuration and baselines remain unchanged.\n" . Nette\Neon\Neon::encode($config, true)) === false) {
        throw new RuntimeException('Cannot write effective PHPStan configuration: ' . $configFile);
    }
    return $configFile;
}

function qaPhpstanPath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function qaPhpstanAbsolutePath(string $path, string $base): string
{
    $path = qaPhpstanPath($path);
    if (preg_match('~^(?:[a-z]:/|/|[a-z][a-z0-9+.-]*://)~i', $path) !== 1) {
        $path = qaPhpstanPath($base) . '/' . $path;
    }
    return qaPhpstanPath(realpath($path) ?: $path);
}

/** Read include paths and explicit provider options, without executing project PHP config. */
function qaPhpstanReadConfig(string $file, string $root, array &$seen, array &$includes, array &$parameters): void
{
    $file = qaPhpstanAbsolutePath($file, $root);
    if (isset($seen[$file]) || !is_file($file)) {
        return;
    }
    $seen[$file] = true;
    $source = (string) file_get_contents($file);
    $data = [];
    if (!str_ends_with(strtolower($file), '.php')) {
        try {
            $decoded = Nette\Neon\Neon::decode($source);
            $data = is_array($decoded) ? $decoded : [];
        } catch (Nette\Neon\Exception) {
            // PHPStan reports the original syntax error with its usual diagnostics.
            return;
        }
    } else {
        // A PHP config may need PHPStan's own classes; it is evaluated by PHPStan.
        // Literal extension paths still let us avoid registering an extension twice.
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = substr($token[1], 1, -1);
                if (str_contains(str_replace('\\\\', '/', $literal), 'shipmonk/')) {
                    $includes[] = $literal;
                }
            }
        }
    }
    foreach (($data['includes'] ?? []) as $include) {
        if (!is_string($include)) {
            continue;
        }
        $include = str_replace('%currentWorkingDirectory%', $root, $include);
        $includes[] = $include;
        if (!str_contains($include, '%')) {
            qaPhpstanReadConfig(qaPhpstanAbsolutePath($include, dirname($file)), $root, $seen, $includes, $parameters);
        }
    }
    $extensionDefaults = str_ends_with($file, '/shipmonk/dead-code-detector/rules.neon')
        || str_ends_with($file, '/shipmonk/phpstan-rules/rules.neon');
    if (!$extensionDefaults && is_array($data['parameters'] ?? null)) {
        $parameters = array_replace_recursive($parameters, $data['parameters']);
    }
}

/** Use project manifests only, so global QA dependencies do not activate framework providers. */
function qaPhpstanProjectPackages(string $root, string $vendorDir): array
{
    $packages = [];
    foreach ([$root . '/composer.json', $root . '/composer.lock', $vendorDir . '/composer/installed.json'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys($data[$section] ?? []) as $name) {
                $packages[$name] = true;
            }
        }
        $installed = array_merge($data['packages'] ?? (array_is_list($data) ? $data : []), $data['packages-dev'] ?? []);
        foreach ($installed as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packages[$package['name']] = true;
            }
        }
    }
    return $packages;
}

/** Read existing Symfony DIC dumps; QA never warms or clears the application's cache. */
function qaPhpstanSymfonyContainers(string $root): array
{
    $paths = [];
    foreach (glob($root . '/var/cache/*/*Container.xml') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $container = simplexml_load_file($file, SimpleXMLElement::class, LIBXML_NONET);
            if ($container !== false && isset($container->services->service)) {
                $paths[] = qaPhpstanPath($file);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
    return $paths;
}
