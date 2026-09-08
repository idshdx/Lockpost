---
name: architecture-conventions
description: Symfony/PHP/Docker coding conventions, test runner commands, and Doctrine migration workflow for sym-pgp-ony. Load before modifying code, running tests, or generating migrations in this repo.
author: user
version: 1.0.0
origin: project
---
# Architecture Conventions — sym-pgp-ony

Project-specific coding and workflow standards. Version-controlled for the team.

## Tech Stack
- **Framework:** Symfony (PHP), asset-mapper frontend, Docker Compose local stack (nginx + php-fpm + mailhog).
- **PHP:** PHP 8.x. See `composer.json` for exact version constraints.
- **PGP domain:** key management / crypto logic in `config/pgp` (GNUPGHOME mounted at runtime).

## Test Runner
- Run the whole suite: `./bin/phpunit` (or `docker compose exec php ./bin/phpunit`).
- Run one file: `./bin/phpunit tests/Path/To/Test.php`.
- Filter: `./bin/phpunit --filter MyTestName`.
- PHPStan/static analysis: `composer analyse` (see `composer.json` scripts if present).
- **Rule:** Never leave the suite red. Tests must be green before committing.

## Database / Migrations
- Migrations in `src/Migrations` (Doctrine). Apply: `docker compose exec php php bin/console doctrine:migrations:migrate`.
- Generate for a schema change: `... doctrine:migrations:diff`.
- Diff against the ephemeral dev DB (docker/mysql volume is git-ignored). Never hand-write SQL into prod.

## Common Local Commands
- Build stack: `docker compose up -d --build`
- PHP dev server / queue / mailhog inspect: refer to `README.md` / `docs/`.
- Formatting: `composer format` if defined; PSR-12 otherwise.

## Conventions
- One feature per merge request; keep commits focused.
- Follow existing naming in `src/` — presume English identifiers and PSR-4 autoloading.
- Update AGENTS.md when adding infrastructure that agents must know about.