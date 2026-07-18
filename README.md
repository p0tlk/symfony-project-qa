# Symfony Project QA

A project-local quality toolkit for Symfony applications. One PowerShell command installs the development dependencies and generates a shared QA configuration for local development and CI.

## Included checks

| Tool | Purpose |
| --- | --- |
| Rector | Automated refactoring and modernization |
| PHP-CS-Fixer | Symfony and PHP 8.4 formatting |
| PHPCBF | Automatic PHPCS violation fixes |
| PHPCS | PSR-12 and application rules |
| Slevomat Coding Standard | Strict types, type hints, and import checks |
| PHPStan | Static analysis |
| Peck | Spelling checks for source code |
| PHPUnit | Optional project test run |

## Requirements

- Windows PowerShell 5.1+ or PowerShell 7+
- PHP 8.4 or newer available as `php`
- Composer 2 available as `composer`
- GNU Aspell and an English dictionary available as `aspell`
- An existing Composer project containing `src/`, or another source folder selected with `-Target`

The generated PHP-CS-Fixer configuration enables `@PHP8x4Migration`, so PHP 8.4 is the minimum supported version.

### Install Aspell

Peck is installed through Composer but delegates dictionary lookups to GNU Aspell, which is a native system program.

```powershell
# Windows with Scoop
scoop install main/aspell
```

```bash
# Debian, Ubuntu, or WSL
sudo apt-get update
sudo apt-get install -y aspell aspell-en

# macOS with Homebrew
brew install aspell
```

## Installation

Download `install-project.ps1` into the root of a Symfony project, then run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\install-project.ps1
```

The installer creates:

```text
.qa/
|-- php-cs-fixer.php
|-- phpcs.xml
`-- rector.php
peck.json
qa.ps1
```

It also updates `composer.json` and `composer.lock`. Commit all generated files so contributors and CI use the same rules and dependency versions.

### Installer options

```powershell
# Analyze app/ instead of src/
.\install-project.ps1 -Target app

# Generate configuration without changing Composer dependencies
.\install-project.ps1 -NoInstall

# Replace existing generated QA files
.\install-project.ps1 -Force
```

## Usage

```powershell
# Run every fixer, then every check
.\qa.ps1 full

# Apply only automated fixes
.\qa.ps1 fix

# Check without modifying source files
.\qa.ps1 check

# Include the project's bin/phpunit test runner
.\qa.ps1 check -WithTests

# Run checks without the Peck/Aspell spelling check
.\qa.ps1 check -SkipPeck
```

`full` and `fix` can change source files. Review the resulting diff before committing.

## PhpStorm External Tools

Add the QA commands to **Settings | Tools | External Tools**. Create a new tool with these common values:

| Field | Windows PowerShell | PowerShell 7 |
| --- | --- | --- |
| Program | `powershell.exe` | `pwsh` |
| Working directory | `$ProjectFileDir$` | `$ProjectFileDir$` |
| Synchronize files after execution | Enabled | Enabled |
| Open console for tool output | Enabled | Enabled |

Use one of the following argument sets for each tool:

| Tool name | Arguments |
| --- | --- |
| Symfony QA: Check | `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" check` |
| Symfony QA: Fix | `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" fix` |
| Symfony QA: Full | `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" full` |
| Symfony QA: Check + tests | `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" check -WithTests` |
| Symfony QA: Check without Peck | `-NoProfile -ExecutionPolicy Bypass -File "$ProjectFileDir$\qa.ps1" check -SkipPeck` |

After saving, run a command from **Tools | External Tools**. You can also assign shortcuts under **Settings | Keymap | External Tools**.

If PhpStorm uses a different PHP interpreter from the terminal, configure the desired CLI executable in **Settings | PHP** and ensure the same PHP version is available to the external tool environment.

## Continuous integration

On a Windows runner:

```powershell
composer install --no-interaction --prefer-dist
.\qa.ps1 check
```

Install Aspell on the runner before executing the QA command, or use `-SkipPeck` when spelling checks are handled separately.

## Peck dictionary

The generated root `peck.json` ignores common PHP and Symfony vocabulary, acronyms, tool names, and generated/dependency directories. Peck expects its configuration in the project root. Add project-specific domain language to `ignore.words` after installation rather than weakening the other checks.
