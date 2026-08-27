# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-08-27

Consolidates the 1.4.0-beta series (beta.1 through beta.11) into a stable
release. Released as a major version because of the `EnforcementMode` rename
below.

### Changed

- **BREAKING:** Renamed `EnforcementMode` cases `SOFT` → `OBSERVE` and
  `STRICT` → `ENFORCE`. The backing string values changed as well
  (`soft`/`strict` to `observe`/`enforce`), so stored configuration values
  need updating too. (#16)
- License-less mint on the Agreement path. `obtainLicenseToken()` no longer
  throws when no `<content>` block path-matches the resource. It mints
  license-less against `{baseUrl}/token` and lets the backend resolve the
  merchant system from the resource URL and the customer's single Active
  Agreement, so a `license.xml` that has drifted cannot veto a mint backed by
  a valid Agreement. A matching block still mints against that block's own
  `{server}/token` with the `<license>` chunk attached. Ports the TypeScript
  SDK change from getsupertab/connect-sdk-typescript#49.
- `resource` carries the raw resource URL on both lanes, no longer the matched
  block's `url` pattern.
- Matched-lane block selection now prefers `<content>` blocks hosted on the
  configured Supertab API host over more-specific third-party matches,
  matching the TypeScript SDK, so a multi-provider license.xml never routes
  client credentials to a third party when a Supertab block also matches.
- License tokens are cached by client id, token server and scope (the block's
  `url` pattern on the matched lane, the resource origin on the Agreement
  lane), so sibling paths reuse a matched token and one Agreement token is
  reused per origin. Previously tokens were cached per exact resource URL.
- URLs are canonicalized the way the WHATWG URL parser (and therefore the
  TypeScript SDK) does: hosts lowercased and punycoded (when ext-intl is
  available), default ports stripped, dot segments (including percent-encoded
  forms) resolved. This applies consistently to origin construction, the
  license.xml fetch, the Agreement-lane cache scope and `<content>` matching.
  A resource URL like `https://EXAMPLE.COM:443/premium` therefore still takes
  the RSL License lane instead of silently falling through to the Agreement
  lane.
- Analytics now defaults to the dedicated ingest service
  (`https://ingest-connect.supertab.co`) instead of the API host. Only the
  analytics host changes: the `/ingest/events` path and payload are the same,
  and token acquisition / JWKS / verification still use the API base URL.
  Override with the new `analyticsBaseUrl` constructor option or the static
  `setAnalyticsBaseUrl()` / `getAnalyticsBaseUrl()` (per-instance option wins).
  Ports the TypeScript SDK 2.2.2 change. (#25)

### Added

- Bot classification analytics: analytics event model with request, browser,
  and CDN-derived signals (`schema_version` 2), a transport contract with HTTP
  and no-op transports, and relay analytics wired into `SupertabConnect`,
  gated behind `analyticsEnabled`. (#8, #9, #11, #17)
- Deferred analytics delivery: on FastCGI SAPIs (PHP-FPM, LiteSpeed, FrankenPHP)
  the relay POST now runs after the response is flushed to the client via
  `fastcgi_finish_request()`, taking it off the user-perceived latency path. It
  falls back to a bounded synchronous POST where that function is unavailable
  (`mod_php`, CLI). Applied automatically by wrapping the default transport in
  the new `DeferredAnalyticsTransport`. Synchronous delivery can be forced via
  the `SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS` environment variable. (#19, #20)
- `AnalyticsTransportInterface` is now a documented extension point for custom
  analytics delivery, with `CallbackAnalyticsTransport` for closure-based routing
  (e.g. onto a WordPress Action Scheduler queue) and `AnalyticsEvent::fromArray()`
  to rehydrate a serialized event across a queue boundary.
- Status self-report endpoint: the SDK serves `/.well-known/supertab/status`
  with component identity details for remote diagnostics. (#21, #22)
- Optional `baseUrl` parameter on the static `SupertabConnect::obtainLicenseToken()`,
  overriding the Supertab API host for a single call. Defaults to `getBaseUrl()`.

### Fixed

- The static `SupertabConnect::obtainLicenseToken()` now shares one token
  cache across calls, mirroring the TypeScript SDK's module-level cache.
  Previously each call constructed a fresh client with an empty cache, so
  repeated facade calls minted a new token every time.
- Analytics events are now emitted through the caller-supplied HTTP client (the
  one passed to `SupertabConnect`) instead of a self-constructed client. On
  hosts that block direct outbound connections and require their own HTTP layer
  (e.g. WordPress VIP via `wp_remote_*`), analytics POSTs to `/ingest/events`
  previously failed silently while the rest of the SDK kept working. The
  short-timeout default client is still used when no client is injected. (#18)
- The `Authorization` header is now read via `getallheaders()` when Apache
  withholds it from `$_SERVER`. (#24)
- Emit a null `source_cdn` when no CDN is in front of the request. (#13)

## [1.3.1] - 2026-06-19

### Changed

- Advertise the RSL license link on allowed responses, not only on denials. (#15)

## [1.3.0] - 2026-04-27

### Added

- Collect incoming request headers in event properties. (#4)
- Send the SDK user-agent string on Supertab Connect API calls. (#3)

## [1.2.0] - 2026-04-08

### Changed

- Removed the implicit fallback to the default bot detector; a bot detector is
  now used only when one is explicitly provided.

## [1.1.0] - 2026-03-31

### Added

- Support for path-only content URLs. (#1)

## [1.0.0] - 2026-03-25

### Added

- Initial stable release: license token verification, RSL license handling,
  bot detection (ported from the TypeScript SDK), robots.txt-style content
  matching, JWKS caching with a pluggable cache interface, and non-intrusive
  result handling that leaves the host response untouched.

[2.0.0]: https://github.com/getsupertab/connect-sdk-php/compare/v1.3.1...v2.0.0
[1.3.1]: https://github.com/getsupertab/connect-sdk-php/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/getsupertab/connect-sdk-php/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/getsupertab/connect-sdk-php/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/getsupertab/connect-sdk-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/getsupertab/connect-sdk-php/releases/tag/v1.0.0
