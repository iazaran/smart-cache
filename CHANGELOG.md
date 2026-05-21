# Changelog

All notable changes to the `iazaran/smart-cache` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
