# Project Structure

```
src/
  Controller/       # Symfony controllers (single: DefaultController)
  Service/          # Business logic services
  Form/             # Symfony form types + DTOs
  Exception/        # AppException + ErrorHandler
  Kernel.php

templates/
  base.html.twig
  default/          # Page templates (index, link, submit, verify)
  email/            # Email templates

tests/
  Controller/       # Functional controller tests
  Service/          # Unit tests for services
  TestHelper.php    # Shared test utilities (PGP dir setup/teardown)
  bootstrap.php

config/
  packages/         # Symfony bundle configuration (yaml)
  routes/           # Route definitions
  services.yaml     # DI container + parameter definitions
  pgp/              # PGP key files (not committed — gitignored)
    private.key     # Server signing key (chmod 600)
    public.key      # Server public key (chmod 644)
    key-config/     # GnuPG keyring directory

assets/
  controllers/      # Stimulus JS controllers
  styles/           # CSS

public/             # Web root (index.php, vendored Bootstrap/jQuery)
docker/
  nginx/            # NGINX Dockerfile + config variants
  php/              # PHP-FPM Dockerfile + conf.d overrides
scripts/
  init-pgp.sh       # Runs inside the container — generates PGP key pair on first setup
  docker-setup-pgp.sh  # Host-side wrapper that shells into the container
  validate-pgp.sh   # Validates key permissions and signing functionality
```

## Architecture Patterns

- Single controller (`DefaultController`) handles all routes
- Services are injected via constructor (autowired); explicit wiring in `services.yaml` for services with non-autowireable scalar args
- `AppException` is the single domain exception type; `ErrorHandler` centralises logging and response formatting
- No database — fully stateless; tokens carry all state (encrypted + HMAC-signed)
- PGP key paths and passphrase come from parameters/env vars, never hardcoded

## Conventions

- Namespace root: `App\` → `src/`; test namespace: `App\Tests\` → `tests/`
- Routes defined via PHP attributes (`#[Route(...)]`) on controller methods
- Form types live in `src/Form/`; associated DTOs (e.g. `MessageSubmitRequest`) live alongside them
- All service exceptions should be `AppException`; catch broad `Exception` only at the boundary and re-throw as `AppException`
- Twig templates follow the `templates/{section}/{page}.html.twig` convention
- Environment-specific config goes in `config/packages/{env}/` subdirectories
