# Symfony Project QA

Run a complete QA suite from the root of a Symfony, Composer, or plain PHP project. The runner streams each tool's output, continues after failures, and saves an offline HTML report plus JSON, Markdown, logs, PHPStan baselines by identifier, and PHPUnit JUnit results.

```powershell
qa                       # Check and report; default mode does not change source
qa full                  # Apply fixes, then check
qa-php                   # Explicit plain PHP mode, no application Composer needed
```

Folders without `composer.json` automatically use plain PHP mode with either command. A framework-free Composer project uses normal Composer mode.

## Included checks

| Tool | Purpose |
| --- | --- |
| Rector | Refactoring and PHP 8.4 modernization |
| PHP CS Fixer | Shared formatting rules |
| PHPCS / PHPCBF + Slevomat | Coding standards, type hints, and automatic fixes |
| PHPStan | Level 8 by default, or the project's configured level |
| [ShipMonk PHPStan rules](https://github.com/shipmonk-rnd/phpstan-rules) | Additional strict checks |
| [ShipMonk dead-code detector](https://github.com/shipmonk-rnd/dead-code-detector) | Unused methods, properties, constants, and enum cases |
| [Composer dependency analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser) | Unused, shadow, and misplaced Composer dependencies |
| [Baseline per identifier](https://github.com/shipmonk-rnd/phpstan-baseline-per-identifier) | Exported PHPStan findings grouped by identifier |
| Psalm | Static analysis, with its Symfony plugin when the project requires FrameworkBundle |
| PHPUnit | Tests when configured, with JUnit output |
| Peck / Aspell | Identifier, filename, and documentation spelling checks |

## Requirements

- Windows PowerShell 5.1+ or PowerShell 7+ for installation.
- PHP 8.4+ on PATH. Default migration and formatting rules target PHP 8.4.
- CLI extensions `mbstring`, `dom`, `SimpleXML`, `xml`, and `xmlwriter`.
- Composer 2 for installing the QA tools.
- GNU Aspell with an English dictionary for spelling checks.

Plain PHP applications do not need their own Composer manifest or autoloader. Composer is used once to install the QA tools, separately from application source. Run with `--skip-peck` when spelling is handled elsewhere; `-NoInstall` generates installation files without checking or installing prerequisites.

Install Aspell with `scoop install main/aspell` on Windows, `sudo apt-get install aspell aspell-en` on Debian/Ubuntu, or `brew install aspell` on macOS.

## Global installation: `qa` on PATH

Clone or download the **entire repository**, then run:

```powershell
.\install-global.ps1
```

The default Windows destination is `%LOCALAPPDATA%\SymfonyProjectQa`. Dependencies live in its `tools/` directory. Its `bin/` directory is added to your user PATH; open a new terminal, enter a project root, and run `qa` or `qa-php`. Nothing is hardcoded to the author's machine.

```powershell
.\install-global.ps1 -Destination C:\Tools\php-qa
.\install-global.ps1 -Force              # Update an existing installation
.\install-global.ps1 -NoInstall -NoPath # Generate files only; keep PATH unchanged
```

On Linux/macOS, run the installer with PowerShell 7 and add the printed `bin/` directory to your shell's PATH. Generated shell launchers use the same PHP runner.

## Project-local installation

Keep the downloaded QA repository outside your application. From the **application root**, invoke its installer:

```powershell
& C:\path\to\symfony-project-qa\install-project.ps1
```

For a Composer project, this installs the tools as development dependencies and updates `composer.json` and `composer.lock`. It detects `src/` or `app/`; select another source directory with `-Target`.

For an application without `composer.json`, it selects the current folder and installs isolated QA dependencies in `.qa/tools/`. It creates no application Composer manifest or `vendor/autoload.php`. `-Plain` explicitly selects this setup even when an application has a Composer manifest.

```powershell
& C:\path\to\symfony-project-qa\install-project.ps1 -Target classes
& C:\path\to\symfony-project-qa\install-project.ps1 -Plain
& C:\path\to\symfony-project-qa\install-project.ps1 -NoInstall
& C:\path\to\symfony-project-qa\install-project.ps1 -Force
```

Generated files:

```text
.qa/
  settings.json           # Default target and plain-mode preference
  php-cs-fixer.php         # Editable wrappers around shared defaults
  phpcs.xml
  rector.php
  runtime/                # Runner, report renderer, configs, spelling helpers
  tools/                  # Plain mode only: tool Composer manifest/lock/vendor
peck.json                 # Created only when it does not already exist
qa, qa.bat, qa.ps1
qa-php, qa-php.bat, qa-php.ps1
```

Run `./qa` on Unix, or `.\qa.bat` / `.\qa.ps1` on Windows. PowerShell requires `.\` for a command in the current folder; the global installation provides the bare `qa` command. Existing root tool configurations and dictionaries are preserved. `-Force` refreshes the generated runtime, launchers, settings, and `.qa` config wrappers; review custom changes to those files before an upgrade. Old project `psalm.xml` and PHPStan configs remain active.

Commit the generated runtime, configuration, Composer manifests and lockfiles. Ignore `.qa/tools/vendor/` and `var/qa/`. For plain projects, contributors and CI install the tools with `composer install --working-dir=.qa/tools`. For Composer projects, use `composer install` after the initial QA dependency installation. After `-NoInstall` in a Composer project, rerun the installer with `-Force` to add the QA development dependencies.

## Commands and CLI output

```powershell
qa check                     # Default: all checks, then reports
qa fix                       # Rector, PHPCBF, PHP CS Fixer changes
qa full                      # Fix, then run all checks
qa-php full                  # The same sequence for standalone PHP
qa --target=classes
qa --report-dir=build/qa
qa --with-tests              # Require tests even without an auto-detected config
qa --skip-tests
qa --skip-peck
qa --timeout=900             # Per-tool timeout; 0 disables it
qa --help
```

The old `-Target`, `-WithTests`, `-SkipTests`, `-SkipPeck`, `-ReportDir`, `-Timeout`, and `-Mode` spellings also work. All modes default to `check`, including `qa.ps1`; specify `full` to retain the original runner's fix-first behavior.

Example excerpt from a standalone project with configured PHPUnit tests:

```text
QA full: C:/Projects/calculator
Project type: plain PHP (4 source files)

==> PHPStan and ShipMonk rules and dead code
 [OK] No errors
PASSED (exit 0, ...s)

==> ShipMonk Composer dependency analyser
SKIPPED: Not applicable in plain PHP mode: no Composer dependencies to analyse.

==> PHPUnit
OK (1 test, 1 assertion)
PASSED (exit 0, ...s)

QA PASSED: 12 passed, 0 failed, 1 skipped.
Open report: C:/Projects/calculator/var/qa/report.html
```

Each failed check is printed with its exit code and diagnostics. Later tools still run. The final process exit is `0` for passed, `1` for failed checks, and `2` for invalid arguments or missing project prerequisites. PHPCBF exit `1` means all fixable errors were fixed; exit `2` remains a failure.

## Reports

Every run writes to `var/qa/` unless overridden:

- `report.html`: offline report with summary, durations, commands, expandable output, and artifact links.
- `report.json`: machine-readable check results and exit codes.
- `summary.md`: Markdown summary.
- Individual tool `.log` files.
- `baselines/_loader.neon` and per-identifier baseline files when PHPStan completes.
- `phpunit.xml`: JUnit results when PHPUnit runs.

Exported baselines are reports, and are **never applied automatically** to analysis. Existing project baselines remain active. A baseline export can succeed while PHPStan fails; the overall run still fails. Reports escape tool output and can be opened directly without a web server.

## Project configuration and plain PHP

| Tool | Configuration searched in order |
| --- | --- |
| PHP CS Fixer | `.qa/php-cs-fixer.php`, `.php-cs-fixer.php`, `.php-cs-fixer.dist.php` |
| PHPCS | `.qa/phpcs.xml`, `.phpcs.xml`, `.phpcs.xml.dist`, `phpcs.xml`, `phpcs.xml.dist` |
| Rector | `.qa/rector.php`, `rector.php`, `rector.php.dist` |
| PHPStan | `phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon` |
| Psalm | `psalm.xml`, `psalm.xml.dist`, `.qa/psalm.xml` |
| Peck | `peck.json`, `.qa/peck.json` |
| Dependency analyser | `composer-dependency-analyser.php`, loaded by the tool |

Global installations use shared defaults only when project configurations are absent. Project installation generates `.qa` wrappers that take precedence; edit or remove those wrappers to select an existing root Rector, PHPCS, or PHP CS Fixer configuration. The runner builds effective PHPStan and default Psalm configs inside the report directory, leaving source configs untouched. PHPStan extensions already registered by project configuration or extension-installer are reused.

PHPStan analyses the source target, Composer autoload/development paths, and `tests/`. Symfony dead-code detection reads existing `var/cache/*/*Container.xml` dumps without warming the application cache. If service construction is reported unused, generate the application's normal development cache or configure `shipmonkDeadCode.usageProviders.symfony.containerXmlPaths`. Custom magic calls can require a [custom usage provider](https://github.com/shipmonk-rnd/dead-code-detector#customization).

Plain mode selects explicit PHP files, including root entry scripts. It excludes dependencies, generated/cache directories, symlinks, QA configs, and current/previous report folders. Generic Rector rules replace Composer/framework sets, and the default PHPCS standard allows classes without namespaces. Peck parses source without executing the application. Dependency analysis is skipped. PHPUnit runs when `phpunit.xml`, `phpunit.xml.dist`, or `bin/phpunit` is present, using the project binary when available and the QA tools' PHPUnit otherwise. PHP CS Fixer may print an informational warning about the missing Composer PHP requirement; this does not fail checks.

Set `QA_TOOLS_HOME` to a tools installation containing `vendor/`, or `QA_TOOLS_VENDOR` to an explicit vendor directory. These are optional; the runner discovers project and installed tool dependencies automatically. `QA_PHP` selects a PHP executable. Project custom Composer `vendor-dir` settings are supported.

## Formatter compatibility and verification

The shared defaults were exercised against a 16-file PHP syntax corpus. Rector, PHPCBF, and PHP CS Fixer converge after the initial fixes; subsequent passes produce no changes and all three checks pass. This guarantee covers the bundled defaults. Custom project rules can introduce conflicts, so rerun the test after modifying them.

```powershell
composer install --working-dir=tools
.\tests\integration.ps1
php .\tests\check-compatibility.php
```

Set `QA_TOOLS_HOME` when testing with another installation, or pass `-ToolsHome` to the integration script. Test fixtures and logs are retained in ignored `tests/work/` and `tests/results/` directories. Integration checks cover both project types, paths with spaces, configured tests, generated output exclusions, source preservation, deliberate failures, per-identifier baselines, and continued reporting after errors.

On Windows the installers call Composer's sibling `composer.phar` directly to preserve version constraints through native arguments. Set `QA_COMPOSER_PHAR` if using a custom Composer batch launcher without that sibling file; the formatter test also accepts this path.

## CI and PhpStorm

For a project-local installation on Windows:

```powershell
composer install              # Or: composer install --working-dir=.qa/tools
.\qa.bat check
```

On Linux/macOS use `./qa check`, or `php .qa/runtime/qa.php check`. Install Aspell first or pass `--skip-peck`. Archive `var/qa/` as CI artifacts even when the command fails.

For PhpStorm External Tools, use `powershell.exe` or `pwsh` with `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" check`, working directory `$ProjectFileDir$`, console output enabled, and file synchronization enabled. Substitute `fix` or `full` for the other actions. Use the same PHP interpreter as the terminal.
