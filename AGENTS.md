# AGENTS.md — Lockpost (sym-pgp-ony)

## Project
Lockpost: shareable-link PGP messaging. Client-side encryption in the browser, server-side signing + email delivery, no message persistence.

## Stack
- PHP 8.3, Symfony 7.1
- OpenPGP.js (browser), GnuPG/PECL gnupg (server)
- NGINX + PHP-FPM, MailHog locally
- Docker + Docker Compose
- PHPUnit 9.5

## Non-goals for agents
- Do not change crypto behavior silently.
- Do not touch key material, `config/pgp`, or passphrase handling unless explicitly asked.
- Do not introduce tracking, cookies, storage, or outbound telemetry.
- Do not run external installers or curl random scripts.

## Required commands
```bash
# start
docker compose up --build -d
# install deps (platform-check disabled in composer.json — no flags needed)
docker compose exec php composer install --no-scripts
# install frontend assets (Stimulus + OpenPGP.js via importmap)
docker compose exec php php bin/console importmap:require @hotwired/stimulus openpgp
docker compose exec php php bin/console importmap:install
# generate server PGP keys (once)
docker compose exec php bash /var/www/app/scripts/init-pgp.sh --with-passphrase your-secure-passphrase
# fix key permissions
docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"
# smoke test
docker compose exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
# full suite
docker compose exec php php bin/phpunit --no-coverage
# clear cache
docker compose exec php php bin/console cache:clear
```

## Windows notes
- Use git-bash / MSYS2 shell for convenience, but pass native paths to native tools when needed.
- App: `http://localhost:8080` (port 8080 by default; change to 80 in docker-compose.yml if port 80 is free).
- MailHog UI: `http://localhost:8025`.
- Key files live under `config/pgp/` and are gitignored.
- Use `docker compose exec php bash -c "..."` wrapper for paths to avoid Windows path mangling.

## Quality bar
- Keep controllers thin; services own crypto, tokens, and HTTP clients.
- Add/adjust tests for behavior changes in `tests/Service` and `tests/Controller`.
- Validate email format and token constraints server-side even if the browser also checks.
- Do not expose raw GPG/system errors to the client; map to generic user-facing messages.

## Known risks to preserve
- Server private key must remain passphrase-protected outside local dev.
- No message storage; do not add persistence without explicit product decision.
- Rate limiting and abuse prevention matter for `/message/submit`.
- Key-server lookups can fail; handle gracefully.

## Deliverables
- Code changes with focused diffs.
- Updated tests for new/changed behavior.
- Brief notes on what changed and why.
