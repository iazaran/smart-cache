# Changelog

All notable changes to the `iazaran/smart-cache` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
