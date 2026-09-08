---
name: symfony-phpunit-upgrade
description: "Upgrade PHPUnit in Symfony projects when asked."
version: 1.0.0
author: Hermes Agent
license: MIT
platforms: [linux, macos, windows]
metadata:
  hermes:
    tags: [symfony, phpunit, testing, php, migration, upgrade]
    related_skills: [test-driven-development, systematic-debugging]
---

# Symfony PHPUnit Upgrade Guide

## Overview

Upgrades PHPUnit in a Symfony project while maintaining test compatibility. Covers version-specific `phpunit.xml.dist` format changes, removal of deprecated listeners (especially `SymfonyTestsListener`), and Symfony PHPUnit Bridge compatibility.

## When to Use

- User asks "upgrade PHPUnit" or "PHPUnit X to Y in a Symfony project"
- PHPUnit version is outdated (e.g., 9.x with PHP 8.3+)
- Symfony 7.4+ project needing PHPUnit 10+ compatibility
- Tests fail after PHPUnit upgrade due to `phpunit.xml.dist` format issues

## Prerequisites

- PHP version compatible with target PHPUnit (PHP 8.2+ for PHPUnit 10, PHP 8.3+ for PHPUnit 11)
- Symfony project with `composer.json` and `phpunit.xml.dist`
- Tests currently passing on the old PHPUnit version

## Workflow

### 1. Determine Target Version

```bash
php -v  # Check PHP version
composer show phpunit/phpunit  # Check current version
```

| PHPUnit | Min PHP | Max PHP |
|---------|---------|---------|
| 9.5     | 7.3     | 8.2     |
| 10.5    | 8.1     | 8.3     |
| 11.5    | 8.3     | 8.4     |

### 2. Update `composer.json` require-dev

```bash
composer require --dev --with-all-dependencies \
    "phpunit/phpunit:^11.0" \
    "symfony/phpunit-bridge:^7.4"
```

If `symfony/phpunit-bridge` blocks the upgrade (pins PHPUnit 9), run separately:
```bash
composer require --dev "phpunit/phpunit:^11.0" --with-all-dependencies
```

### 3. Update `phpunit.xml.dist` Format

PHPUnit 10+ removed several deprecated config elements from PHPUnit 9.

#### Remove `SymfonyTestsListener` (or migrate to extension)

**Option A — Remove it (quickest fix):**
```xml
<!-- REMOVE this block from phpunit.xml.dist -->
<listeners>
    <listener class="Symfony\Bridge\PhpUnit\SymfonyTestsListener"/>
</listeners>
```

**Option B — Use as extension (if supported):**
```xml
<extensions>
    <extension class="Symfony\Bridge\PhpUnit\SymfonyTestsListenerExtension"/>
</extensions>
```

> **Pitfall:** Symfony PHPUnit Bridge v7.4.x still references the removed `TestListenerDefaultImplementation` trait. If you get trait-related errors, use Option A (remove it).

#### Convert `<coverage>` to PHPUnit 10+ format

```xml
<!-- PHPUnit 9 format (REMOVE) -->
<coverage processUncoveredFiles="true">
    <include>
        <directory suffix=".php">src/</directory>
    </include>
</coverage>
```
```xml
<!-- PHPUnit 10+ format -->
<source>
    <directory suffix=".php">src/</directory>
</source>
```

#### Complete example `phpunit.xml.dist` for PHPUnit 11

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnRisky="true">

    <testsuites>
        <testsuite name="Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>

    <source>
        <directory suffix=".php">src/</directory>
    </source>

    <php>
        <env name="APP_ENV" value="test"/>
        <env name="APP_DEBUG" value="1"/>
    </php>
</phpunit>
```

### 4. Update Test Annotations (Optional)

PHPUnit 11 deprecates annotation-based metadata in favor of PHP attributes:
```php
#[DataProvider('provideCases')]
#[CoversClass(MyService::class)]
```

### 5. Rebuild and Test

```bash
docker-compose build php 2>&1
docker container prune -f
docker-compose run --rm php bash -c 'cd /var/www/app && ./vendor/bin/phpunit --version'
```

> **Pitfall:** `BootstrapTest` and other `WebTestCase` tests may hang when the Docker kernel boots. This is a pre-existing environment issue, not a PHPUnit version problem. Run unit tests in isolation to confirm the upgrade works.

### 6. Update Composer Platform Constraint

```json
"config": {
    "platform": {
        "php": "8.4"
    },
    "platform-check": true
}
```

### 7. Update Documentation

- `README.md`: Update PHPUnit version reference
- `docs/CHANGELOG.md`: Add upgrade entry under "Changed"

## Common Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `SymfonyTestsListener` not found or trait errors | Bridge v7.4 uses PHPUnit 9 listener API | Remove `<listeners>` block |
| `<coverage processUncoveredFiles="true">` warning | Deprecated in PHPUnit 10+ | Use `<source>` element |
| `<listeners>` element not allowed | PHPUnit 10+ removed it | Remove entirely |
| Deprecation warnings for `@dataProvider` | PHPUnit 11 prefers attributes | Use `#[DataProvider]` for new tests |
| `BootstrapTest` hangs in Docker | Kernel boot contention in `WebTestCase` | Run unit tests only — pre-existing issue |
| Platform constraint mismatch | `config.platform.php` set to old version | Update to match runtime PHP |

## Verification Checklist

- [ ] `./vendor/bin/phpunit --version` shows target version
- [ ] `phpunit.xml.dist` passes syntax check
- [ ] Unit tests pass (no `<listeners>` or deprecated `<coverage>`)
- [ ] `composer.json` `platform.php` matches runtime PHP
- [ ] README and CHANGELOG updated