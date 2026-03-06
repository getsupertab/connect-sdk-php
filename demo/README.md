# Supertab Connect PHP SDK — Demo

Interactive CLI demo showing the full RSL bot-accessing-protected-content lifecycle.

Two modes are available:

| Mode | Command | What it exercises |
|------|---------|-------------------|
| **Local** | `composer demo:local` | Both publisher + bot sides of the SDK, self-contained |
| **Real API** | `composer demo` | Bot side only, talks to a real RSL-protected site |

## Local Mode (recommended for getting started)

Spins up a local PHP server that acts as both a publisher (using the SDK to verify tokens) and a mock Supertab Connect API (issuing JWTs). No external credentials needed.

```bash
composer demo:local
```

Optionally set a custom port:

```bash
DEMO_PORT=9090 composer demo:local
```

### What runs locally

- **Publisher server** at `localhost:8080` — uses `SupertabConnect::handleRequest()` to protect `/article`
- **Mock JWKS endpoint** at `localhost:8080/.well-known/jwks.json/platform` — serves an auto-generated EC P-256 public key
- **Mock token endpoint** at `localhost:8080/token` — issues JWTs signed with the matching private key
- **License XML** at `localhost:8080/license.xml` — RSL-compliant content licensing descriptor

## Real API Mode

Runs the bot against a real RSL-protected site. Only exercises the customer/bot side of the SDK.

### Prerequisites

- An RSL-protected site to hit (with license.xml)
- Supertab bot client credentials

### Setup

1. Copy the environment template:
   ```bash
   cp demo/.env.example demo/.env
   ```

2. Fill in `demo/.env` (only needed for real API mode, `demo:local` mocks these):
   - `RESOURCE_URL` — URL of a protected page on an RSL-enabled site
   - `SUPERTAB_CLIENT_ID` — Your bot operator client ID
   - `SUPERTAB_CLIENT_SECRET` — Your bot operator client secret

## Demo Steps

The script walks through 6 steps interactively. Press ENTER to advance between steps.

1. **Title screen** with configuration overview
2. **Bot requests the resource URL** with no token — gets **401** with `WWW-Authenticate` and `Link` headers
3. **Bot follows the Link** to `/license.xml` — discovers licensing terms and token endpoint
4. **Bot uses SDK** to obtain a license token (`client_credentials` flow)
5. **Bot retries the resource URL** with the token — gets **200 OK** with content
6. **Bot tries a fake token** — gets **401** (signature verification catches it)
