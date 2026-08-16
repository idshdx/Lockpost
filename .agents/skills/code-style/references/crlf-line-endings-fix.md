# CRLF Line Ending Fix for Shell Scripts

## Problem
Shell scripts (`scripts/*.sh`) committed with CRLF line endings fail inside Docker containers running Linux.

### Symptom
```bash
docker compose exec php bash /var/www/app/scripts/init-pgp.sh
```
Output:
```
/var/www/app/scripts/init-pgp.sh: line 2: set: -\r: invalid option
/var/www/app/scripts/init-pgp.sh: line 3: $'\r': command not found
/var/www/app/scripts/init-pgp.sh: line 7: $'\r': command not found
/var/www/app/scripts/init-pgp.sh: line 66: syntax error: unexpected end of file
```

### Root Cause
When files are created or edited on Windows, they may use CRLF (`\r\n`) line endings. Inside the Docker Linux container, `bash -e\r` is parsed as `set -e` followed by a carriage return character, which is treated as a separate command/argument, causing syntax errors.

### Fix
Convert CRLF → LF for all shell scripts:
```bash
sed -i 's/\r$//' scripts/*.sh
```

### Prevention
`.gitattributes` already has `*.sh eol=lf` (line 12), which normalizes line endings on commit. However, if files were added to the index before `.gitattributes` was in place, they may still have CRLF in the working tree.

Run `git add --renormalize .` after adding the `.gitattributes` rule to fix existing committed files.
