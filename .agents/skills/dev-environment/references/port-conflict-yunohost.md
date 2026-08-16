---
name: port-conflict
description: Diagnosing and resolving port conflicts when running the application locally.
---

# Port Conflict Error Transcript

## Symptom
`http://localhost` returns a 302 redirect instead of the sym-pgp-ony app.

## Diagnosis
```
$ curl -sI http://localhost
HTTP/1.1 302 Moved Temporarily
Server: nginx
Location: https://localhost/admin
```

```
$ docker compose ps
NAME      IMAGE                 COMMAND                  PORTS
nginx     nginx:stable-alpine   ...                       80/tcp, 443/tcp    ← NOT published to host!
```
The nginx container shows `80/tcp` (internal only) — no `0.0.0.0:80->80/tcp`. Docker silently failed to bind port 80 because it's already in use.

## Root Cause
Another local web server is running its own nginx on port 80. When Docker tries to bind `80:80`, it silently fails.

## Fix
Change `docker-compose.yml` from `"80:80"` to `"8080:80"`:
```yaml
nginx:
  ports:
    - "8080:80"
    - "443:443"
```
App becomes accessible at `http://localhost:8080`.
