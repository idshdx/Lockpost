# Cross-Platform Skill Audit Notes (2026-08-16)

## Canonical principle
Project skills must be OS-neutral. Avoid Windows-only wording like Docker Desktop, Git Bash/MSYS2, C:/Users/mihai, /c/Users, PowerShell, YunoHost, or any host-specific path munging.

## Local development skill
- Replace hardcoded user paths with clone instructions.
- Docker commands stay as `docker compose`, not platform-specific wrappers.
- Port conflict references should be generic: "another local web server," not YunoHost.
- Document `chown` via `bash -c "..."` as a normal container command, not a Windows workaround.

## SSH skill
- Use `~/.ssh/...` instead of absolute Windows paths.
- Mention Git Bash/MSYS2 only when it changes invocation, not as a requirement.

## Test runner skill
- Keep env vars container-scoped.
- Keep `--no-deps` and `--ignore-platform-req=ext-opcache` guidance; those are cross-platform facts.

## Reference file hygiene
- Add frontmatter to session notes so they are discoverable skill references.
- Keep filenames concept-based, not host-based.
