# Self-Report Demo Site

Vanilla-PHP publisher for testing the `/.well-known/supertab/status`
self-report endpoint end-to-end against the real (sandbox) Supertab Connect
API. Every request flows through `SupertabConnect::handleRequest()` — the
status endpoint is served by the SDK itself, with zero endpoint-specific
code in this app. It also serves as the canonical "plain PHP" integration
reference.

Unlike the sibling `demo/` CLI demo (self-contained, mock API), this app
pins the **released Packagist SDK** and talks to the real API. Testing a new
SDK release = bump the pin in `composer.json`, rebuild, push.

## Configuration

| Env var | Default | Purpose |
|---------|---------|---------|
| `SUPERTAB_MERCHANT_API_KEY` | — (required) | Sandbox merchant API key |
| `SUPERTAB_BASE_URL` | `https://api-connect.sbx.supertab.co` | API base URL |
| `SUPERTAB_ENFORCEMENT` | `observe` | `disabled` \| `observe` \| `enforce` — reflected in the status payload |
| `SUPERTAB_ANALYTICS` | on (`0`/`false`/`off` to disable) | Toggles analytics → the payload's `eventReporting` |

## Run locally

```bash
composer install
SUPERTAB_MERCHANT_API_KEY=<key> php -S localhost:8080 index.php
```

Smoke checks:

```bash
curl -s localhost:8080/healthz                                # → ok
curl -si localhost:8080/.well-known/supertab/status | head -5 # → 404 {"supertab":true}
curl -s localhost:8080/ | head -3                             # → demo HTML page
```

## Deploy to AWS App Runner

App Runner has no managed PHP runtime, so it deploys from a container image
in ECR.

```bash
AWS_ACCOUNT=<account-id> AWS_REGION=<region>
REPO=$AWS_ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com/supertab-self-report-demo

aws ecr create-repository --repository-name supertab-self-report-demo --region $AWS_REGION
aws ecr get-login-password --region $AWS_REGION | docker login --username AWS --password-stdin $REPO
docker build --platform linux/amd64 -t $REPO:latest .
docker push $REPO:latest
```

Create the service (console or CLI): **source** = the ECR image with
auto-deployment on push, **port** = `8080`, **size** = 0.25 vCPU / 512 MB,
**health check** = HTTP on `/healthz`, and the env vars above (at minimum
`SUPERTAB_MERCHANT_API_KEY`).

## Register the site (required for probes)

The backend only mints status challenges (`aud` = origin) for origins it
knows. Register the service URL — `https://<id>.<region>.awsapprunner.com`
— as a merchant website in **sandbox**. If the URL changes (service
recreated), re-register.

## Probe flow

1. Unauthenticated: `curl -si https://<host>/.well-known/supertab/status`
   → `404` + `{"supertab":true}` + `Cache-Control: no-store` (decoy).
2. Garbage bearer: same decoy, never a 500 (challenge verification fails
   closed).
3. Backend live-health probe for the registered site → `200` with
   `{runtime, sdkVersion, component: {kind: "php-sdk", version},
   enforcement, eventReporting}`.
4. Flip `SUPERTAB_ENFORCEMENT` / `SUPERTAB_ANALYTICS` on the service →
   next probe reflects the change.
