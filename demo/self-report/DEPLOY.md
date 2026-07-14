# Deploying the Self-Report Demo Site (Fly.io)

The live instance runs at **https://supertab-self-report-demo.fly.dev** —
one always-warm 256 MB machine (~$2–3/mo), TLS automatic, remote builds
(no local arch concerns). `fly.toml` in this directory is the canonical
config; it pins `auto_stop_machines = 'off'` / `min_machines_running = 1`
because a probe target must never cold-start.

> An AWS App Runner variant of this runbook existed previously; it was
> dropped after IAM friction (`iam:PassRole`) — see git history if ever
> needed.

## One-time setup

```bash
brew install flyctl
flyctl auth login                      # browser flow (signup included)

cd demo/self-report
flyctl apps create supertab-self-report-demo
flyctl secrets set SUPERTAB_MERCHANT_API_KEY=placeholder SUPERTAB_ENFORCEMENT=observe --stage
flyctl deploy --ha=false               # single machine; fly.toml does the rest
```

The API key starts as `placeholder` on purpose: sandbox registration needs
the site's domain, which only exists after the first deploy. Everything
probe-related works meanwhile — `/healthz` short-circuits before the config
check and challenge verification uses the public platform JWKS; only
analytics delivery would 401-and-drop.

## Smoke test

```bash
HOST=supertab-self-report-demo.fly.dev
curl -s  https://$HOST/healthz                                  # → ok
curl -si https://$HOST/.well-known/supertab/status | head -5    # → 404 {"supertab":true}, no-store
curl -s  https://$HOST/ | grep "SDK version"                    # → the pinned SDK version
```

## Register the site in sandbox and set the real key (required)

1. Register `https://supertab-self-report-demo.fly.dev` as a merchant
   website in the **sandbox** environment — registration issues the
   merchant API key. The backend only mints status challenges with `aud` =
   a registered origin; unregistered probes silently get the decoy.
2. Swap in the real key (this alone triggers a redeploy, ~30 s):

   ```bash
   flyctl secrets set SUPERTAB_MERCHANT_API_KEY=<real-key>
   ```

## The end-to-end probe

Fire a backend live-health check (`self_report`) for the registered site.
Expected: `200` with `runtime: null`, `sdkVersion`,
`component: {kind: "php-sdk", version}`, `enforcement: "observe"`,
`eventReporting: true`.

Note: the backend resolves only `ts-sdk` against a registry so far
(laterpay/supertab-connect#1094); `php-sdk` degrades to "show version, no
nudge" until its resolver lands. Expected, not a failure.

## Updating (each new SDK release)

```bash
cd demo/self-report
# bump the pin in composer.json, then:
composer update getsupertab/connect-sdk-php
flyctl deploy --ha=false
```

Commit the pin + lockfile change back to the repo.

**Config experiments** (no rebuild): `flyctl secrets set
SUPERTAB_ENFORCEMENT=enforce` (or `SUPERTAB_ANALYTICS=0`,
`SUPERTAB_BASE_URL=…`) — each set redeploys, and the next probe reflects
the new values.

**Ops one-liners**: `flyctl status` (machine state), `flyctl logs`
(live tail), `flyctl apps destroy supertab-self-report-demo` (teardown).
