# Changelog

All notable changes to the `iazaran/smart-cache` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.13.3] - 2026-08-31

### Security
- The dashboard's circuit-breaker state is now HTML-escaped before being rendered, and the CSS class derived from it is restricted to a safe character set. With `circuit_breaker.shared` enabled the state is read from the application cache, so any process able to write that entry could previously inject markup into the dashboard page.
- `CircuitBreaker` now validates the state it hydrates from shared cache against the three known values instead of trusting the stored payload, so a corrupted or hostile entry cannot put the breaker into an unrecognised state.
- Every dashboard endpoint re-checks `smart-cache.dashboard.enabled` at request time and returns 404 when it is off. Routes are registered from the service provider, so a `route:cache` taken while the dashboard was enabled baked them into the compiled route file and left all five endpoints live after the setting was switched back off. Dashboard route registration now also honours `routesAreCached()`, matching Laravel's own `loadRoutesFrom()`.
- Dashboard route registration guards `routesAreCached()` behind an `instanceof CachesRoutes` check, matching Laravel's own `loadRoutesFrom()`. `routesAreCached()` is not declared on the `Application` contract, so calling it unconditionally would raise `Error: Call to undefined method` on any container that does not implement `CachesRoutes` (Lumen, custom kernels).
- `smart-cache:clear --force` and `smart-cache:status --force` enumerate Redis keys with `SCAN` instead of `KEYS *`. `KEYS` is O(N) and runs to completion on Redis' single-threaded event loop, stalling every other client for the duration of the sweep. The key set returned is unchanged, and any driver that does not support a usable `SCAN` transparently falls back to the previous call. Redis **cluster** connections deliberately keep using `KEYS`: cluster `SCAN` walks a single node and would report only part of the keyspace, and a partial view is unsafe for a sweep that decides what to delete.
- Documented that `dashboard.middleware` ships as `['web']` — session and CSRF handling, but **no authentication** — in the README, the full documentation, and the published config file. The endpoints expose the managed cache key list and internal metrics. The default is unchanged so existing deployments keep working; add `auth` (and a gate) before exposing the dashboard.

### Fixed
- Write deduplication (Cache DNA, enabled by default) no longer shortens an entry's lifetime. Re-writing a key with unchanged content skipped the store write entirely, so the expiry was never extended and the value disappeared at the *first* write's TTL — breaking every "refresh this key on each pass" pattern. The `_sc_dna:{key}` record now stores the absolute expiry alongside the `xxh128` hash, and a write is skipped only when the stored entry provably outlives the newly requested window. Records written by earlier releases are a bare hash string; they are read safely, never treated as a skip signal, and are upgraded in place on the next write.
- Write deduplication could serve a stale value. `forever()`, `add()`, `touch()`, and the SWR regeneration path (`flexible()`, `swr()`, `stale()`, `refreshAhead()`) changed a value or its expiry without maintaining the deduplication record, so a later `put()` compared against content or an expiry that was no longer in the store and skipped a write that was genuinely required. Writing value `Y`, then `forever()`-ing `X`, then writing `Y` again left `X` in the cache while `put()` reported success. All of these paths now invalidate the record.
- `put()` with a TTL of zero, a negative TTL, or a past `DateTimeInterface` is a delete in Laravel, but deduplication skipped it — any live entry trivially satisfies a past expiry — so the value survived. Non-positive expiries are now never deduplicated.
- Deduplication expiry maths now uses the framework clock (`Carbon::now()`) rather than `time()`, matching how the underlying store computes expiries and making the behavior testable with `Carbon::setTestNow()`.
- `SmartCache::clear()` / `clearManaged()` removed nothing when a namespace was active. Managed keys are stored fully qualified, but the namespace was applied to them a second time on the way out, so the delete targeted `tenant:tenant:key`.
- `cleanupExpiredManagedKeys()` had the same double-prefixing defect and therefore classified *every* live namespaced key as expired, silently emptying the managed-key index. Because `healthCheck()` calls it, a single health check could destroy the index that pattern invalidation, audits, and the dashboard depend on.
- `CacheInvalidationService` re-applied the active namespace to already-qualified managed keys, making `flushPatterns()`, `invalidateModel()`, `getCacheStatistics()`, and `healthCheckAndCleanup()` silent no-ops for namespaced callers.
- `LazyChunkedCollection` returned `null` for every item outside the first chunk. Chunks are written with `array_chunk(..., preserve_keys: true)`, so chunk *N* is keyed by the original offsets, but the collection indexed each chunk by a chunk-relative position. Iteration, `offsetGet()`, `slice()`, `each()`, `filter()`, and `map()` were all affected whenever `strategies.chunking.lazy_loading` was enabled.
- `LazyChunkedCollection::toArray()` used `array_merge()`, which renumbered integer keys and diverged from the eager restore path for sparse or non-sequential integer keys. Keys are now preserved, and an item whose key collides with one already collected is appended rather than overwriting it, so a collection assembled by hand from chunk-relative (0-based) chunks still yields every item.
- Model wildcard invalidation never matched. `CacheInvalidation::matchesPattern()` replaced the bare `*` after `preg_quote()` had already escaped it, compiling `user_*` to `/^user_\.*$/` — a literal dot — so `invalidatesPatterns(['user_*'])` matched nothing and stale entries survived indefinitely. Now mirrors the correct implementation in `CacheInvalidationService`.
- `CompressionStrategy::restore()` corrupted the process-wide error-handler stack. `set_error_handler($previous)` pushes a frame rather than popping one, so two handlers leaked per call; the application's own `restore_error_handler()` then popped the wrong frame and left SmartCache's error-swallowing closure active, silently discarding application warnings. Now uses `restore_error_handler()`.
- `SmartCache::memo()` could return the wrong value. `MemoizedCacheDriver::get()` detected a miss by comparing the fetched value against the caller's `$default`, so reading a stored `false`/`0`/`''`/`[]` with a matching default recorded a hit as a miss and poisoned `has()`/`get()` for the rest of the request. Reads now go through an internal sentinel.
- `MemoizedCacheDriver` leaked memory in long-running workers (Octane, queue workers). `evictIfNeeded()` only measured the value map, leaving the `accessOrder` and negative-lookup maps unbounded; `accessOrder` entries were also never released by the write paths.
- `SmartCache::__destruct()` and `CostAwareCacheManager::__destruct()` persisted to the cache without a guard. A backend failure during PHP shutdown — a torn-down connection, a Redis timeout, a read-only replica — turned a successfully served request into an uncatchable fatal error.
- `CostAwareCacheManager::persist()` skipped writing an emptied metadata map, so forgetting the last tracked key left the previous entry in cache and the metadata reappeared on the next load.
- A foreign or corrupted entry stored under `_sc_performance_metrics` or `_sc_cost_metadata` raised a `TypeError` on read instead of being ignored.
- `healthCheckAndCleanup()` no longer iterates a key list captured before the expiry sweep, and tolerates a chunked wrapper whose `chunk_keys` list is missing or malformed rather than iterating `null`.

### Changed
- Expect the `cache_write_dedup` performance counter to fall sharply after upgrading. Writes are now skipped only when the stored entry already outlives the requested TTL, instead of on any content match, so far fewer writes qualify. This is the intended trade-off: the previous counter was inflated by skips that were silently shortening cache lifetimes. Existing `_sc_dna:*` entries mismatch once after upgrade and are transparently rewritten; during a rolling deploy old and new code interoperate safely, with deduplication simply disabled for keys whose record was last written by the other version.
- `putWithJitter()` and `rememberWithJitter()` now always apply the percentage they are given. They previously routed through `applyJitter()`, which is gated on `smart-cache.jitter.enabled` — disabled by default — so both methods silently stored values with an unmodified TTL unless global jitter happened to be on. Calling them is the opt-in; `applyJitter()` keeps its existing flag-gated behavior for direct callers, and the fluent `withJitter()` modifier is unchanged.

- The dashboard's "Hit Rate" card always displayed `N/A`. It read a top-level `hit_rate` key, but `getPerformanceMetrics()` reports the value as `cache_efficiency.hit_ratio`. The card now shows the real figure; the old key is still honoured for hand-built payloads.

### Changed (internal)
- `ClearCommand` and `StatusCommand` now share a `SmartCache\Console\Concerns\EnumeratesCacheKeys` trait instead of carrying identical private copies of the key-enumeration helpers. No command signature or output changed.

### Documentation
- Corrected the documented dashboard URL: the dashboard is served at `GET /smart-cache` (the route is registered at `/` under the configured prefix), not `GET /smart-cache/dashboard`. Also documented the `/commands` endpoint.
- Corrected the Cache DNA description in the README and full documentation: deduplication skips a write only when the content is unchanged *and* the stored entry already outlives the requested TTL.
- Fixed the documented `rememberWithJitter()` example, which passed the callback and the jitter percentage in the wrong order and would raise a `TypeError`.
- Documented that the explicit `putWithJitter()` / `rememberWithJitter()` methods do not depend on the global `jitter.enabled` flag.

## [1.13.2] - 2026-08-04

### Security
- Updated the transitive `guzzlehttp/guzzle` dependency from 7.15.1 to 7.15.2 to resolve GHSA-v5mv-p594-2x33 and GHSA-f7vp-7xgx-4w4r. SmartCache does not directly require Guzzle, `composer.json` is unchanged, and `composer audit` is clean after the lock-file update.

### Changed
- Redesigned the full documentation with a modern responsive layout, clearer navigation and content hierarchy, improved code blocks and cards, accessible active states, reduced-motion support, and better direct-link behavior.

### Fixed
- Replaced the GitHub stars badge's generic dynamic-JSON API lookup with Shields' dedicated GitHub stars endpoint to prevent intermittent invalid badge output.

## [1.13.1] - 2026-07-21

### Security
- Updated the repository's locked Guzzle stack to `guzzlehttp/guzzle` 7.15.1, `guzzlehttp/promises` 2.5.1, and `guzzlehttp/psr7` 2.13.0 to resolve four upstream advisories reported on 2026-07-20. SmartCache does not directly require Guzzle; the packages are present through the Laravel development/test dependency graph. `composer audit` is clean after the update.

### Added
- Added PHP 8.5 to the GitHub Actions compatibility matrix for Laravel 12 and 13, the upstream-supported framework lines that support PHP 8.5.
- Added `SmartCache::clearManaged()` as an explicit concrete-class and facade API for removing SmartCache-tracked entries without flushing unrelated keys. The public `SmartCache` contract is unchanged so third-party implementations remain compatible.
- Added enterprise adoption guidance covering appropriate workloads, unsuitable payloads, internal metadata keys, upstream runtime support windows, incremental migration, and explicit cache-clearing scope.

### Changed
- Clarified that SmartCache 1.x preserves managed-only `clear()` behavior while `flush()` clears the entire underlying store. Existing behavior is unchanged; new application code should prefer the scope-explicit `clearManaged()` or `flush()` methods.
- Repositioned the README and full documentation around SmartCache's primary value: safe optimization and invalidation for large Laravel cache payloads. Claims such as zero overhead, universal opt-in behavior, and unchanged full PSR-16 semantics were replaced with precise operational guidance.
- Removed the redundant `ext-json` suggestion and installation prerequisite because JSON is always enabled in PHP 8+.
- Updated the security policy to identify 1.13.x as the maintained release line and distinguish package compatibility from upstream PHP and Laravel security support.

### Fixed
- Removed obsolete reflection `setAccessible()` calls, which have no effect on the supported PHP 8.1+ range and emit deprecation warnings on PHP 8.5.
- Replaced invalid `asyncSwr()` Closure examples with serializable invokable-class examples; queued refresh callbacks reject closures by design.
- Corrected SWR documentation to distinguish the synchronous refresh behavior of `swr()`, `stale()`, and `refreshAhead()` from the queue-backed `asyncSwr()` method, and corrected rollback guidance for optimized cache wrappers.
- Corrected the full documentation's Cache DNA description from MD5 to the `xxh128` algorithm used since 1.12.1.

## [1.13.0] - 2026-06-16
### Added
- Declarative model invalidation rules via a protected `cacheInvalidation(): array` method. Existing fluent setters still work and are merged with declared rules.
- `Model::flushCacheTags()` for explicit invalidation after `saveQuietly()`, query-builder updates/deletes, upserts, mass inserts, raw SQL, or any other path that bypasses Eloquent events.
- `TagFlushed` event, including the tag name, live key count, and source (`manual`, `model`, or `model_helper`), when cache events are enabled.
- Config options for metadata locks (`smart-cache.metadata_lock.*`) and transaction-aware model invalidation (`smart-cache.model_invalidation.after_commit`).

### Changed
- Model auto-invalidation now defers cache flushing until the active database transaction commits by default. Rollbacks no longer flush cache, and nested transactions wait for the outer commit. Set `smart-cache.model_invalidation.after_commit` to `false` to restore immediate invalidation.
- Tag metadata writes now register before the cache value is written and use a short Laravel cache lock when the store supports `LockProvider`, reducing lost tag-index updates under concurrent writers while preserving best-effort behavior for stores without locks.
- Tag reads lazily prune expired or missing key references, and tag flushes correctly handle keys written under an active namespace.

### Fixed
- `SmartCache::add()` no longer leaks active tags into the next write when the atomic add fails because the key already exists.
- Cache DNA deduplicated writes now still refresh tag/managed-key metadata and only skip the value write when the cached value is still present.
- Dependency invalidation now refreshes dependency metadata before traversal, so long-running workers do not miss relationships added by another process after the local map was loaded.

## [1.12.2] - 2026-05-29
### Security
- Upgraded every remaining `symfony/*` lockfile entry from `v8.0.8` to `v8.1.0` (>= patched lines `8.0.12` / `8.0.13`) to clear the rest of the open Dependabot advisories plus two pending CVEs surfaced by `composer audit`. Runtime: `symfony/mailer` (CVE-2026-45068, `SendmailTransport` argument injection via dash-prefixed recipient), `symfony/routing` (CVE-2026-45065, `UrlGenerator` route-requirement bypass via unanchored regex alternation; CVE-2026-48784, dot-segment encoding skip), `symfony/http-foundation` (CVE-2026-48736), `symfony/http-kernel` (CVE-2026-45075, `#[IsGranted(methods: ['GET'])]` filter bypass via `HEAD`). Dev: `symfony/yaml` (CVE-2026-45133 uncontrolled recursion, CVE-2026-45304 collection-alias "Billion Laughs", CVE-2026-45305 `Parser::cleanup()` ReDoS). `composer audit` is now clean across runtime and dev scopes. `composer.json` is unchanged — the existing ranges already permitted these versions; Dependabot was failing because of a stale resolver state on its side.

## [1.12.1] - 2026-05-29
### Security
- Upgraded `symfony/mime` from `v8.0.8` to `v8.1.0` (>= patched line `8.0.12`) to address GHSA Email Header / SMTP Command Injection via CRLF in `Symfony\Component\Mime\Address` and Email Header Injection via Non-Token Characters in Mime Parameter Names. Transitive bumps: `symfony/deprecation-contracts` `v3.6.0` → `v3.7.0`, `symfony/polyfill-intl-idn` `v1.36.0` → `v1.38.1`, `symfony/polyfill-intl-normalizer` `v1.36.0` → `v1.38.0`, `symfony/polyfill-mbstring` `v1.36.0` → `v1.38.1`. No package API change.
### Changed
- `SmartCache::contentHash()` (Cache DNA write-deduplication hot path) now uses `xxh128` (PHP 8.1+, already a hard requirement) instead of `md5`. Output is still 32 lowercase hex characters, so the `_sc_dna:{key}` storage format is unchanged. Significantly faster on every `put()` when deduplication is enabled (default `true`). Existing `_sc_dna:*` entries from prior releases will mismatch once after upgrade and be transparently overwritten on the next `put()`; no errors, no data corruption.
### Added
- `tests/Unit/SmartCacheTest.php::test_cache_dna_hash_format_is_stable` locks the stored DNA hash contract (32 lowercase hex characters, deterministic for identical inputs, sensitive to value changes) so a future algorithm swap that breaks the key-length assumption is caught immediately.

## [1.12.0] - 2026-05-21
### Fixed
- `CompressionStrategy::restore()` now explicitly validates the `data` field, the base64 decode step, the `gzdecode()` decompression step, and the `unserialize()` step, throwing `RuntimeException` on any failure. Previously a corrupted compressed payload could surface as a silent PHP warning followed by a `false`/garbage return value, which the cache layer would then re-cache. The `unserialize()` call is now wrapped with a temporary error handler so corrupted payloads no longer leak `E_NOTICE` warnings into application logs (round-tripping the value `false` still works).
- `SmartCache::maybeRestoreValue()` self-healing now evicts the full footprint of a corrupted entry: the wrapper key, the SWR/stampede metadata (`_sc_meta:{key}`), the Cache DNA hash (`_sc_dna:{key}`), the managed-keys index entry, and — when the wrapper is a chunked value — every chunk key referenced by `chunk_keys` and the orphan-chunk registry entry. Previously the chunk keys could survive as orphans after a self-heal pass.
- `BackgroundCacheRefreshJob::__construct()` now rejects `Closure` callbacks up-front with a clear `InvalidArgumentException` ("does not accept Closures …") instead of failing later inside Laravel's queue serializer with a generic "Serialization of 'Closure' is not allowed" error. The `callable|string` signature is unchanged; only the runtime guard is new.
### Added
- Opt-in single-flight SWR: when `smart-cache.swr.single_flight = true` and the underlying cache store implements `Illuminate\Contracts\Cache\LockProvider` (redis, memcached, database, dynamodb, file/array via lock files), `refreshInBackground()` now acquires a short non-blocking lock keyed on `_sc_swr_refresh:{key}` so only one worker regenerates a stale entry. Concurrent workers continue to serve the stale value without piling up redundant callback executions. Default `false` preserves the historical behaviour.
- `SmartCache::reset()` — a new public method that clears all per-request state (`activeTags`, `activeNamespace`, dirty flags, in-memory performance-metric buffers, managed-keys load flag). The service provider now calls `reset()` from its `terminating()` hook so Laravel Octane, Swoole, FrankenPHP, and RoadRunner workers no longer leak tag/namespace state between requests. The hook is a no-op outside long-running runtimes.
- Opt-in bounded managed-keys index: `smart-cache.managed_keys.max_tracked` (default `0` = unlimited) caps the in-memory `_sc_managed_keys` index. When exceeded, the oldest entries are dropped FIFO to prevent the index from growing without bound in high-cardinality workloads. Default behaviour is unchanged.
- Opt-in debounced chunk-registry persistence: `OrphanChunkCleanupService` now accepts a `persistEvery` constructor argument (default `1` = persist every change, current behaviour). When raised, registry mutations buffer in memory and flush every N changes. The service provider always calls `flush()` from `terminating()` so buffered changes are not lost between requests.
- Opt-in shared circuit breaker state: when `smart-cache.circuit_breaker.shared = true`, the breaker mirrors its state (`state`, `failure_count`, `success_count`, `opened_at`) to a shared cache key (`_sc_circuit_breaker:{driver}`, TTL `smart-cache.circuit_breaker.shared_ttl`, default `300s`) so all workers in a pool observe the same `OPEN`/`CLOSED`/`HALF_OPEN` decision. Default `false` preserves per-instance behaviour.
- 18 new unit tests across `tests/Unit/V112FeaturesTest.php` and `tests/Unit/Strategies/CompressionStrategyTest.php` covering: compression-decode failure paths (invalid base64, corrupted gzip stream, missing data field, corrupted serialized payload, no warning leakage), self-healing eviction of chunked and compressed wrappers, SWR single-flight lock behaviour (lock held → callback skipped; disabled flag → synchronous refresh), `reset()` clearing namespace/tag state, `BackgroundCacheRefreshJob` closure rejection, bounded managed-keys cap, BC-safe unbounded default, debounced registry persistence + `flush()`, shared circuit-breaker visibility across instances, per-instance default, and SWR meta-key TTL co-residency.
### Changed
- `config/smart-cache.php` documents the four new opt-in keys (`swr.single_flight`, `managed_keys.max_tracked`, `circuit_breaker.shared`, `circuit_breaker.shared_ttl`). All defaults preserve v1.11.0 behaviour.
- `README.md` and `docs/index.html` document the v1.12.0 changes, the Octane reset hook, the SWR single-flight option, and replace the static "tests-452 passed" badge with a real GitHub Actions CI badge.

## [1.11.0] - 2026-05-04
### Fixed
- `touch()` now extends the TTL of every chunk key, the SWR/stampede metadata key (`_sc_meta:{key}`), and the Cache DNA hash key (`_sc_dna:{key}`) in addition to the wrapper key. Previously, calling `touch()` on a chunked entry left the underlying chunks scheduled to expire at their original TTL, which could surface as `RuntimeException: Missing cache chunk […]` on subsequent reads.
- `touch()` now returns `false` when the target key does not exist, matching Laravel cache semantics across both the native (Laravel 13+) and fallback paths.
- `SmartSerializationStrategy::isJsonSafe()` now performs a JSON encode/decode round-trip and rejects values whose decoded form does not strictly equal the original (e.g. `stdClass` collapsing to an empty array, `Exception` instances losing their class, and similar type-changing payloads). Forced-`json` mode degrades to `php` when the value cannot be safely round-tripped.
- JSON serialization writes float values with `JSON_PRESERVE_ZERO_FRACTION`, so values like `1.0` round-trip as float instead of being silently coerced to `int(1)`.
### Changed
- `isJsonSafe()` rejects top-level resources, closures and non-`stdClass` objects upfront, and runs the round-trip check with `JSON_THROW_ON_ERROR` so that nested unsupported types do not emit unsuppressable `E_WARNING`s into application logs.
- `CostAwareCacheManager::trimIfNeeded()` now trims down to 90% of `max_tracked_keys` instead of exactly the cap, amortising the `arsort()` cost across multiple inserts. Memory ceiling is unchanged. Behaviour with `max_tracked_keys < 1` is now well-defined (metadata is cleared).
- `ChunkingStrategy::shouldApply()` estimates value size by sampling the serialized bytes of up to five items instead of using a fixed 50-byte-per-item heuristic, producing more accurate chunk decisions for non-trivial item sizes while keeping the borderline-case full-serialize fallback intact.
- `SmartChunkSizeCalculator::calculateAverageItemSize()` walks the first N items instead of calling `array_rand()`, removing RNG overhead and the `is_array($samples)` defensive branch.
- `.gitignore` now excludes the `.codex` directory used by AI tooling.
### Added
- Unit tests covering chunked `touch()` (happy path and chunk-failure path), `JSON_PRESERVE_ZERO_FRACTION` preservation, `stdClass`/`Exception`/nested-object fallbacks, forced-`json` graceful degradation, legacy JSON payload restore compatibility, and dedicated tests verifying `isJsonSafe()` does not emit warnings for top-level resources, top-level closures, or nested resources.
- Feature tests covering end-to-end `touch()` on chunked entries (value still resolves and every chunk key survives) and `touch()` returning `false` for missing keys.
- Unit tests for `CostAwareCacheManager` covering cost-based scoring, the new 90%-of-capacity trimming behaviour, the `max_tracked_keys = 1` edge case, and persist/load round-trip.

## [1.10.0] - 2026-04-20
### Added
- Added `smart-cache:audit` for read-only diagnostics of managed keys, missing tracked keys, broken chunked entries, orphan chunks, large unoptimized values, and cost-aware eviction suggestions.
- Added `smart-cache:bench` for benchmarking raw Laravel cache operations against SmartCache optimization profiles, with table output, JSON output, driver selection, profile selection, iteration control, report-file export, and per-profile goal/result summaries.
- Added `docs/benchmark-report-redis.json`, generated from the package itself with PHP 8.4, Laravel 13, Redis, and ten iterations.
- Added console tests for audit and benchmark commands, including JSON report validation, benchmark file export, broken chunk detection, data integrity, and key-shape preservation.
### Changed
- Updated `README.md`, `docs/index.html`, and `TESTING.md` with audit and benchmark workflows, local benchmark guidance, and the expanded test count.
- Registered audit and benchmark command metadata so package consumers can discover them through `SmartCache::getAvailableCommands()`.
### Fixed
- Preserved sparse numeric keys when restoring eager chunked arrays.
- Treated missing chunks as corrupted cache entries so self-healing can evict them and `remember()` can regenerate clean data instead of returning a cached `null`.

## [1.9.3] - 2026-04-14
### Added
- Added `SECURITY.md` for standardized enterprise vulnerability disclosures.
- Added `CHANGELOG.md` following the Keep a Changelog standard.
- Added `.editorconfig` to enforce formatting consistency across contributors.
- Issue and Pull Request GitHub templates added to standardize bug tracking.
### Changed
- Enriched `config/smart-cache.php` inline documentation for advanced strategies like `adaptive mode` and `circuit_breaker`.
- Appended `ext-zlib` and `ext-json` extension suggestions to `composer.json`.
- Enhanced `CONTRIBUTING.md` with test commands, PSR-12 standards, and security disclosure references.
- Added `zlib` extension to CI workflows for explicit compression test coverage.
- Updated documentation and `README.md` with deep-dive troubleshooting and "Best Practices" examples.
- Added `composer.lock` to `.gitignore` (library best practice).
### Fixed
- Fixed dashboard route documentation (`/stats` → `/statistics`) in README and `docs/index.html`.

## [1.9.2] - 2026-03-17
### Added
- Feature: Added official support for Laravel 13 framework requirements.
- Implemented `touch()` method functionality and boot-safe event registration to comply with Laravel 13 architectures.
### Fixed
- Fixed tests for Symfony Console compatibility allowing both `add()` and `addCommand()`.

## [1.9.1] - 2026-03-04
### Changed
- Improved current features functionality.
- Enhanced and updated the `README.md` and documentation files for a better Developer Experience (DX).

## [1.9.0] - 2026-02-20
### Added
- Added Write Deduplication (Cache DNA) optimization to save unnecessary I/O writes.
- Self-Healing Cache feature and Conditional Caching functionality implemented.
- Significant SEO improvements in `composer.json` and meta-tags.
### Fixed
- Addressed assorted bug fixes reported by the community.

## [1.8.0] - 2026-02-08
### Added
- Introduced Cost-Aware caching to prioritize memory eviction efficiently.
- Various under-the-hood fixes and optimization refinements.

## [1.7.0] - 2026-01-19
### Added
- Feature Request #31: Implemented `store()` method support directly on the SmartCache facade.

## [1.6.0] - 2025-12-06
### Added
- General core improvements and new minor features for package robustness.

## [1.5.0] - 2025-10-19
### Added
- New core features and expanded cache optimization solutions for large objects.

## [1.4.3] - 2025-09-27
### Changed
- Extended documentation coverage in `docs/index.html` and `README.md`.

## [1.4.2] - 2025-09-26
### Changed
- Improved the internal mechanism for managing keys for mass invalidation dynamically.

## [1.4.1] - 2025-09-26
### Changed
- Optimization applied to the `flexible` macro implementation.

## [1.4.0] - 2025-09-26
### Added
- Comprehensive dependency tracking mechanism.
- Full Cache Tag support implementations.
- Powerful cache invalidation strategies and helpers.

## [1.3.7] - 2025-09-25
### Added
- Robust test coverage established ensuring compatibility with PHP 8.1+ and Laravel 8+.

## [1.3.6] - 2025-09-08
### Fixed
- Bugfix: Resolves Issue #18 where cache `flexible` logic was not operating as expected under certain payloads.

## [1.3.5] - 2025-09-02
### Fixed
- Resolved PHP parameter type warnings related to strict nullable types.

## [1.3.4] - 2025-09-02
### Fixed
- Bugfix: Issue #14 regarding keys stored but not registering properly on status checks.

## [1.3.3] - 2025-09-01
### Fixed
- Bugfix: Addressed Issue #12 covering data cleared by a specifically defined key.

## [1.3.2] - 2025-08-31
### Fixed
- Continued stabilization for manual key data clearance mechanics (Issue #12).

## [1.3.1] - 2025-08-31
### Fixed
- Bugfix: Resolved Issue #8 concerning multiple conflicting strategies applying to single cache allocations.

## [1.3.0] - 2025-08-31
### Added
- Feature Request #7: Implemented dedicated support for cache `flexible` macros.

## [1.2.2] - 2025-08-30
### Added
- Deployed structured unit testing cases asserting early system stability.

## [1.2.1] - 2025-06-03
### Added
- Added Google site verification file for better SEO visibility compliance.

## [1.2.0] - 2025-06-03
### Changed
- Various repository refinements tailored towards improving SEO and discoverability.

## [1.1.0] - 2025-04-22
### Added
- Introduced the `smart_cache` global helper function to provide a drop-in analogue for Laravel's `cache` helper.

## [1.0.1] - 2025-04-21
### Changed
- Package-wide code refactoring, structural cleanup, and PSR standard reformatting.

## [1.0.0] - 2025-04-21
### Added
- Initial package scaffolding and base logic commit.

[1.13.2]: https://github.com/iazaran/smart-cache/compare/1.13.1...1.13.2
[1.13.1]: https://github.com/iazaran/smart-cache/compare/1.13.0...1.13.1
[1.13.0]: https://github.com/iazaran/smart-cache/compare/1.12.2...1.13.0
[1.12.2]: https://github.com/iazaran/smart-cache/compare/1.12.1...1.12.2
[1.12.1]: https://github.com/iazaran/smart-cache/compare/1.12.0...1.12.1
[1.12.0]: https://github.com/iazaran/smart-cache/compare/1.11.0...1.12.0
[1.11.0]: https://github.com/iazaran/smart-cache/compare/1.10.0...1.11.0
[1.10.0]: https://github.com/iazaran/smart-cache/compare/1.9.3...1.10.0
[1.9.3]: https://github.com/iazaran/smart-cache/compare/1.9.2...1.9.3
[1.9.2]: https://github.com/iazaran/smart-cache/compare/1.9.1...1.9.2
[1.9.1]: https://github.com/iazaran/smart-cache/compare/1.9.0...1.9.1
[1.9.0]: https://github.com/iazaran/smart-cache/compare/1.8.0...1.9.0
[1.8.0]: https://github.com/iazaran/smart-cache/compare/1.7.0...1.8.0
[1.7.0]: https://github.com/iazaran/smart-cache/compare/1.6.0...1.7.0
[1.6.0]: https://github.com/iazaran/smart-cache/compare/1.5.0...1.6.0
[1.5.0]: https://github.com/iazaran/smart-cache/compare/1.4.3...1.5.0
[1.4.3]: https://github.com/iazaran/smart-cache/compare/1.4.2...1.4.3
[1.4.2]: https://github.com/iazaran/smart-cache/compare/1.4.1...1.4.2
[1.4.1]: https://github.com/iazaran/smart-cache/compare/1.4.0...1.4.1
[1.4.0]: https://github.com/iazaran/smart-cache/compare/1.3.7...1.4.0
[1.3.7]: https://github.com/iazaran/smart-cache/compare/1.3.6...1.3.7
[1.3.6]: https://github.com/iazaran/smart-cache/compare/1.3.5...1.3.6
[1.3.5]: https://github.com/iazaran/smart-cache/compare/1.3.4...1.3.5
[1.3.4]: https://github.com/iazaran/smart-cache/compare/1.3.3...1.3.4
[1.3.3]: https://github.com/iazaran/smart-cache/compare/1.3.2...1.3.3
[1.3.2]: https://github.com/iazaran/smart-cache/compare/1.3.1...1.3.2
[1.3.1]: https://github.com/iazaran/smart-cache/compare/1.3.0...1.3.1
[1.3.0]: https://github.com/iazaran/smart-cache/compare/1.2.2...1.3.0
[1.2.2]: https://github.com/iazaran/smart-cache/compare/1.2.1...1.2.2
[1.2.1]: https://github.com/iazaran/smart-cache/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/iazaran/smart-cache/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/iazaran/smart-cache/compare/1.0.1...1.1.0
[1.0.1]: https://github.com/iazaran/smart-cache/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/iazaran/smart-cache/releases/tag/1.0.0
