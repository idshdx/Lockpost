---
name: connect-ssh
description: Connect from the local machine to the Oracle VPS Ubuntu server via SSH or salt-ssh using the project key.
---

# Connect to Oracle VPS

**Use when:** You need to run commands or deploy to the production Oracle VPS at 129.159.7.42.

## SSH Connection

```bash
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42
```

## Salt-SSH Connection (preferred for deployments)

The VPS has Salt master installed. The project includes salt configuration under `salt/`:

- `salt/roster` — targets `lockpost-vps` at `129.159.7.42`, user `ubuntu`, key `~/.ssh/ssh-key-oracle.key`
- `salt/states/lockpost.sls` — deployment state (pull, build, restart, verify)
- `salt/master` — salt master config (file_roots and pillar_roots)

**Note:** `salt-ssh` is NOT installed on this VPS. The existing setup uses `salt-master`/`salt-minion`, but the minion is not running. For deployments, use direct SSH or install `salt-ssh` on the local machine.

## Notes

- The key file is `ssh-key-oracle.key` (not the `.pub` file).
- The remote user is `ubuntu`.
- If prompted to trust the host fingerprint on first connection, confirm it.
- The app is deployed at `/opt/lockpost/` on the VPS.
- The production compose file is `docker-compose.prod.yml`.
- `GNUPGHOME=/var/www/app/config/pgp/key-config` is set in the prod container environment.
- PGP keys live at `/opt/lockpost/config/pgp/` and are bind-mounted into the container.
