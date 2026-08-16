---
name: connect-ssh
description: Connect from the local machine to the Oracle VPS Ubuntu server via SSH using the project key.
---

# Connect to Oracle VPS

Use this skill when you need to SSH into the production Oracle VPS.

## Command

```bash
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42
```

## Notes

- The key file is `ssh-key-oracle.key` (not the `.pub` file).
- The remote user is `ubuntu`.
- If prompted to trust the host fingerprint on first connection, confirm it.
