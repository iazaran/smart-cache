# Changelog

All notable changes to the `iazaran/smart-cache` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.17.0] - 2026-10-29

A maintenance release of fixes and performance improvements found in a full audit of 1.16. Nothing in it requires a code change: the `SmartCache` contract is unchanged, no default changes, and 1.16 and 1.17 read each other's entries. One opt-in config key is added (`serializable_classes`, default `null`). A few fixes change what an edge case returns; they are listed under **Changed**.

### Added
- Opt-in `smart-cache.serializable_classes` (default `null`, no restriction). Laravel's `cache.serializable_classes` is applied by the store, and the Laravel 13 application skeleton (`laravel/laravel` 13.x `config/cache.php`) sets it to `false`. SmartCache unserializes the values it compresses, encrypts, or serializes itself, so that setting does not reach them: a large object came back intact where a small one would be `__PHP_Incomplete_Class`. Set `serializable_classes` to `'cache'` to follow Laravel's setting, to `false`, or to a list of allowed classes. Chunked values are read by the store and already follow Laravel's setting. igbinary payloads of the opt-in serialization strategy cannot be restricted.
- `LazyChunkedCollection` implements `JsonSerializable`. `json_encode()` produced `{}`, and a controller could not return a lazily loaded value.
- Smaller public API additions, all optional: `setSerializableClasses()` on the compression, adaptive compression, encryption, and serialization strategies (trait `SmartCache\Strategies\Concerns\RestrictsUnserialization`); `CostAwareCacheManager::refresh(int $maxAgeSeconds = 0)`; an optional `$collectionClass` argument for `LazyChunkedCollection` and `$storeName` argument for `BackgroundCacheRefreshJob`. One new internal key, `_sc_warned:encryption_pattern:{hash}`, is written only when an encryption pattern is invalid.

### Performance
- **Bulk invalidation writes the managed-key index at most once.** Every 10 keys removed from `_sc_managed_keys`, SmartCache re-read and rewrote the whole index under a lock. Flushing a tag that covers thousands of keys rewrote an index of tens of thousands of keys hundreds of times. `flushTags()`, `tags(...)->flush()`, `flushPatterns()`, `invalidateModel()`, `invalidate()`, `deleteMultiple()`, and `flushNamespace()` now write it at most once, at the end (`flushNamespace()` also writes pending changes before it reads the index). Small removals keep the usual schedule (once 10 changes are pending, or at the end of the request), so a model save that flushes one tag writes the index no more often than before. The stored result is the same.
- **`remember()` serializes a large value less.** With the default monitoring and cost-aware scoring, a miss serialized the new value twice more just to measure it: once for the cost-aware size, and once for the write metric when no strategy changed the value. The size `put()` measures is now reused. For a 2 MB payload that saves about 3 ms per miss in our measurements. The recorded numbers are the same.
- The dashboard's HTML page (`GET /smart-cache`) no longer runs `getStatistics()` and `healthCheck()` on every view. It discarded both results, and each reads every managed value; `healthCheck()` also prunes the index and evicts broken entries. `/statistics` and `/health` are unchanged, and a subclass that overrides `renderDashboard()` still receives both.
- `memo()`'s LRU eviction no longer sorts the whole memo layer for every new key once it is full; the access order is kept as it is recorded. In our measurement, 50,000 distinct reads through a full memo layer took 0.2 s instead of 2.3 s.
- Pattern invalidation converts each glob pattern to a regular expression once per call instead of once per managed key.
- A shared circuit breaker (`circuit_breaker.shared`) no longer writes its state to the cache on every success while it is closed with no failures.

### Fixed
- **Orphan chunks**
  - `cleanupOrphanChunks()` and `smart-cache:cleanup-chunks` could lose registry entries, so the chunks they listed stayed in the store forever. A pass wrote back the registry it had read, dropping chunks registered while it ran. A second pass in the same process (Octane, a queued cleanup) also started from the previous pass's copy instead of the stored registry. A pass now starts from the stored registry and applies only its own changes to it. `OrphanChunkCleanupService::registerChunks()` and `unregisterChunks()` now apply their change to the stored registry instead of writing this instance's copy as a whole.
  - `ChunkingStrategy::setCleanupService()` is deprecated: SmartCache registers chunks itself, under a metadata lock, once the entry is stored.
- **Lazy loading**
  - Turning `strategies.chunking.lazy_loading` off now applies to entries written while it was on. They kept coming back as a `LazyChunkedCollection`, which for entries stored without expiry was forever. Entries written with lazy loading off stay eager when it is turned on, as before.
  - `materialize()` returns the cached collection class, such as `Eloquent\Collection`, as an eager read does. It returned a `Support\Collection`.
  - `slice()` with a negative offset or length counts from the end, as `array_slice()` does. It returned out-of-range positions as `null`.
- **Values that cannot be serialized.** On Laravel's `array` store with `serialize => false` (common in tests), `Cache::put()` keeps a value holding a Closure, but `SmartCache::put()` threw "Serialization of 'Closure' is not allowed" while measuring it for monitoring, hashing it for deduplication, or sizing a large array for chunking. Such values are now stored without optimization or a deduplication record.
- **Queued refreshes write to the store they came from.** `SmartCache::store('redis')->refreshAsync()` and `->asyncSwr()` queued a job that wrote the refreshed value to the default store, so the redis entry stayed stale and a new job was queued every `$ttl`. The job now carries the store name. See the rolling-deploy note under **Changed**.
- **`flushNamespace()`** starts from the stored managed-key index, as `clearManaged()` does since 1.16, so a namespace's keys that other processes tracked since this process loaded the index are flushed too.
- **Metadata locks** no longer apply an update twice. When releasing the lock failed after the update had run (for example a Redis connection dropping), the update ran again without the lock.
- **SWR and stampede protection use the framework clock.** `flexible()`, `swr()`, `stale()`, `refreshAhead()`, `asyncSwr()`, and `rememberWithStampedeProtection()` used `time()` for freshness, while the store expires entries by Laravel's clock. Under `Carbon::setTestNow()` or `$this->travel()` an entry never went stale, unlike with Laravel's `Cache::flexible()`. In production both clocks agree.
- **Console commands**
  - `smart-cache:clear --force` skipped non-managed keys when every managed key had expired.
  - `smart-cache:clear 123`, `smart-cache:status --force`, and the dashboard's clear command treated a numeric managed key as not managed: the index returns `'123'` as the integer `123`. Their lookups were also a full pass over the index for every scanned key.
  - `smart-cache:clear --force` stopped at the first key it could not read or delete (such as a tag set of Laravel's own tagged cache on Redis). It now continues and reports how many keys it could not clear.
  - `smart-cache:warm --warmer=name` warns about a name that is not configured instead of warming nothing without a word.
- **`flushPatterns()` and `invalidateModel()`** forget a key matched by several patterns once. It was forgotten again for every further pattern and counted again on stores whose `forget()` always reports success (database).
- **`memo()`**: a key that missed and was then stored with `remember()` or `rememberForever()` on the memo layer read as missing again once LRU evicted it.
- **Circuit breaker (shared state)**: `getStats()` reported no `time_until_recovery` for a breaker another process had opened, and a breaker another process had closed kept reporting its old `opened_at`.
- **Cost-aware scoring in long-running processes.** The scores were loaded once per process and written back as a whole, so an Octane worker or queue worker overwrote other processes' scores with a copy that could be hours old. After a request (`reset()`) or before a queued job (`refreshState()`), a copy older than a minute is now dropped and read again on next use.
- **Encryption**: a pattern in `strategies.encryption.patterns` that is not a valid regular expression (a glob such as `user_token_*`) matches nothing, so the values it was meant to protect are stored unencrypted. That is unchanged, but it is now logged as a warning, at most once an hour per pattern (throttled through the cache store), instead of happening silently.
- **Robustness**
  - A chunk that is not an array (overwritten or corrupted) raises a clear "Invalid cache chunk" error, so self-healing evicts the entry. It was skipped with a PHP warning.
  - `SmartChunkSizeCalculator::getRecommendations([])` and `calculateChunkCount(0, 0)` no longer divide by zero.
  - `AdaptiveCompressionStrategy::getCompressionStats()` no longer divides by zero, and its frequency persistence (also run from its destructor) catches every `Throwable`.
- The config comment for `rate_limiter.enabled` now says the flag is not read: `throttle()` and `rememberWithStampedeProtection()` always rate-limit.

### Changed
- **Upgrade note — queued refreshes during a rolling deploy.** A refresh job queued by 1.17 from `store('name')` and run by a 1.16 worker writes to the default store, as in 1.16 (the worker ignores the job's new property). Jobs queued by 1.16 have no store and keep writing to the default store. Upgrade queue workers together with, or before, the servers that queue refreshes from `store()`.
- **Upgrade note — lazy loading.** If you turned `strategies.chunking.lazy_loading` off, entries written while it was on are now returned eagerly (an array or collection) instead of as a `LazyChunkedCollection`.
- `smart-cache:clear --force` now clears non-managed keys when every managed key had expired, as it already did when the index was empty.
- A chunked entry with a chunk that is not an array is treated as corrupted: self-healing evicts it, so `remember()` regenerates it, instead of returning partial data with a PHP warning.
- `LazyChunkedCollection::materialize()` returns the cached collection class (a subclass of `Support\Collection`, so existing type hints still pass), and `json_encode()` of a `LazyChunkedCollection` gives a list of its items instead of `{}`.
- `flushPatterns()` and `invalidateModel()` return a lower count when patterns overlap: each key is counted once.
- `flushNamespace()` reads the managed-key index from the store before it starts, and flushes the namespace's keys other processes tracked too (never fewer than before).
- Long-running processes (Octane, queue workers) read cost-aware scores again when their copy is older than a minute: at most one extra read of `_sc_cost_metadata` a minute per process.

### Documentation
- README rewritten to be shorter: why SmartCache, what makes it production-ready, and the essentials, with details in the full documentation.
- Documented `serializable_classes`, and the 1.17 fixes in the full documentation.
- `SECURITY.md` lists 1.17.x as the maintained release line.

## [1.16.0] - 2026-10-20

This release answers three community requests (tag-driven flushing of chunked data, `Cache::memo()` interoperability, and chunk lifecycle) and aligns SmartCache with Laravel 13.33/13.34. The `SmartCache` contract is unchanged, one opt-in config key is added (`memo.native`, default `false`), and entries written by 1.15 are read unchanged. Read the upgrade notes under **Changed**: a few defects are fixed in ways that change behavior.

### Added
- Opt-in native memoization: `smart-cache.memo.native` (default `false`). When enabled on Laravel 12.9+, `SmartCache::memo()` is built on Laravel's `Cache::memo()`. There is one memo layer per store, shared with `Cache::memo()` and with every other `memo()` call, and Laravel clears it between requests and queued jobs. SmartCache still compresses, chunks, and restores above it, and its own tags work on it (and on every store). In this mode `add()` writes to the store directly, because Laravel's memoized store has no atomic `add()`; a key added that way is then read from the store by SmartCache for the rest of the request (Laravel's own `Cache::memo()` still remembers its earlier miss). Like `Cache::memo()`, a key the request has already read as missing keeps reading as missing until this request writes it. On Laravel versions without `Cache::memo()`, `memo()` keeps its own layer. **Default unchanged:** each `memo()` call still creates its own layer.
- Laravel `Repository` parity:
  - `BackedEnum` and `UnitEnum` keys are accepted everywhere Laravel accepts them (`get`, `put`, `has`, `forget`, `remember`, `add`, `increment`, `touch`, `many`, `pull`, ...). They previously threw "could not be converted to string". Enum tag names are accepted in `tags([...])` and `flushTags()`.
  - The typed getters `string()`, `integer()`, `float()`, `boolean()`, `array()`, and `missing()` and `rememberWithWarmth()` are implemented on SmartCache. They previously fell through to the raw repository, so on compressed or chunked keys they returned the internal `_sc_compressed` / `_sc_chunked` wrapper, and they ignored the namespace.
  - `remember()`, `rememberWithWarmth()`, `rememberIf()`, and `rememberWithLock()` accept a Closure TTL that receives the value, as Laravel 13's `remember()` does.
  - `tags('a', 'b')` tags with both names, like `tags(['a', 'b'])`; the second name was silently dropped.
  - `supportsTags()` returns `true`: SmartCache's own tag index works on every store, including file, database, and DynamoDB.
  - `flexible()` accepts `DateInterval` and `DateTimeInterface` durations. A second call previously threw "could not be converted to int".
- `SmartCache::refreshState()`. Before each job, `queue:work` daemons now call it (on the `Looping` event): pending bookkeeping is persisted, and the managed-key index, dependency map, and metrics are re-read on next use. Pending tags are dropped. The namespace and runtime settings are kept. The sync queue is not affected.
- `LazyChunkedCollection::materialize()` loads every chunk and returns what an eager read returns.
- `SmartCache\Strategies\RestoreOnlyStrategy`, a decoder for a strategy that is switched off (see **Fixed**).

### Fixed
- **Chunked data**
  - Each chunked write now uses new chunk keys (`_sc_chunk_{key}_{version}_{index}`), and the replaced entry's chunks are deleted once the new entry is stored. Chunks used to be rewritten in place, so a concurrent reader could get a mix of old and new rows without any error. A failed main-key write also left the old entry pointing at new chunks.
  - Shrinking a chunked value no longer leaves its trailing chunks behind, and neither does replacing it with a value that is not chunked when it is stored without expiry. Such chunks used to stay in the store forever for `forever()` / `rememberForever()` keys.
  - A store that rejects a chunk (Memcached refuses items over its size limit) no longer leaves an entry that can never be read while `put()` reports success. The chunks already written are deleted and the value falls back to compression or the plain value.
  - Write deduplication no longer skips a chunked write. It used to skip the main entry while still rewriting the chunks with the new TTL, so the entry outlived its chunks and turned into a miss early.
  - A read that races a rewrite is no longer evicted. When a chunked entry's chunks are missing, SmartCache reads the entry again: if it was replaced meanwhile, the new value is returned (following up to three replacements) instead of self-healing evicting it. Only an entry that is still stored with missing chunks is evicted; one that keeps being replaced is a miss. The health check applies the same re-check before removing an entry with missing chunks.
  - `put()` with a TTL of zero or less deletes the entry with its chunks and metadata, as `forget()` does. Laravel treats such a TTL as a delete, but the chunks were written and then left behind.
  - `forget()` reads the entry from the store rather than through `memo()`, so it deletes the current chunks rather than ones remembered from an earlier version. It also deletes the entry before its chunks.
  - With `strategies.chunking.lazy_loading`, the stale value `flexible()` / `swr()` returns is loaded before the refresh replaces it. It previously combined old row positions with refreshed chunks.
  - `strategies.chunking.smart_sizing` no longer forces at least 100 items per chunk, which produced chunks larger than the driver limit for large items (100 × 20 KB on Memcached's 1 MB limit).
- **The orphan-chunk registry is now maintained.** `ChunkingStrategy::setCleanupService()` was never called, so the registry stayed empty and `smart-cache:cleanup-chunks` never found anything. SmartCache now registers the chunks of every chunked value stored without expiry and unregisters them when they are deleted. Chunks of a concurrent write that lost the race stay registered until cleanup removes them. These are the only chunks that could otherwise stay in the store forever, after an eviction, a deletion outside SmartCache, or a concurrent write that lost. Chunks of values with a TTL expire with them and are not registered. `cleanupOrphanChunks()` now also deletes registered chunks that the current entry no longer points at, and runs under a metadata lock.
- **`memo()` correctness**
  - SmartCache's bookkeeping (deduplication records, tag and dependency indexes, the managed-key index, SWR metadata, metrics, the circuit breaker's shared state, and rate-limit counters) is no longer read through the memo layer. A deduplication record remembered from before another process wrote the key made `memo()->put()` skip a needed write and report success. Tag-index updates under the metadata lock were based on a remembered copy and dropped other processes' entries.
  - `memo()->flush()` now also clears the memo layer, which kept serving the values the flush removed.
  - The memo layer passes other repository methods (`getDefaultCacheTime()`, `getName()`, ...) through to the store, and `touch()` with a TTL of zero or less forgets the memoized value.
- **Tags**
  - `tags('t')->putMany([...])` and `setMultiple()` tag every key. Only the first was tagged, so `flushTags('t')` left the others stale.
  - `flushTags()` removes the flushed keys from the tag index instead of deleting the index, so a key another process tags meanwhile stays tagged.
- **Turning a strategy off no longer breaks the entries it wrote.** Entries written while compression, chunking, encryption, or serialization was enabled came back as internal wrappers (`['_sc_compressed' => true, ...]`) once it was disabled, until they expired, which for `rememberForever()` entries was never. Disabled strategies still decode; they just stop encoding. Disabled encryption only resolves the encrypter when an encrypted entry is read, so applications without encryption still need no `APP_KEY` for SmartCache.
- **The managed-key index no longer loses keys across processes.** Each process wrote its in-memory copy of `_sc_managed_keys` back as a whole, dropping keys that other processes tracked meanwhile. Those keys then escaped `clear()`, `flushPatterns()`, and model invalidation. `persistManagedKeys()` now re-reads the index under a metadata lock and applies only its own additions and removals. `clearManaged()` starts from the stored index, and `cleanupExpiredManagedKeys()` removes only the expired keys.
- **Redis key enumeration matches managed keys.** `SCAN` returns keys with the connection prefix (`database.redis.options.prefix`) and the cache prefix (`cache.prefix`) in front, so `smart-cache:clear --force`, `smart-cache:status --force`, and `smart-cache:audit` never matched a managed key. Both prefixes are now removed, and keys without them (queues, sessions, other stores on the same connection) are left out. See the upgrade note for `clear --force`.
- **Lazy loading no longer returns partial data.** A `LazyChunkedCollection` whose chunk is missing (evicted, or deleted because the entry was replaced after the collection was returned) now throws a `RuntimeException`. Previously its items read as `null` and `toArray()` left them out.
- **Invalidation**
  - Under a namespace, `flushPatterns()`, `invalidateModel()`, and model `patterns` also match the namespace's keys. Patterns were matched against fully qualified keys, so `namespace('t1')->flushPatterns(['user_*'])` removed the global `user_*` keys and left `t1:user_*` stale. The global matches are still removed, as before.
  - Model placeholders such as `category_{category_id}_products` are also resolved with the values the model had before an update. Moving a product to another category left the old category's entry stale, including when invalidation is deferred until after commit (the default).
  - An enum-cast attribute in a placeholder resolves to its value (backed) or name (pure). It previously threw from `save()`, after the row had already been written.
  - Dependencies follow the namespace. The dependency map stored keys without the namespace, so tenants that used the same key names shared one edge. When one tenant invalidated it, the edge was consumed, and another tenant's later `invalidate()` left its dependent key stale. Under a namespace, `dependsOn()` now also stores the edge with the namespace on both ends, and `invalidate()` follows that tenant-only edge first, then the un-namespaced edges, exactly as before. Without a namespace nothing changes.
- **Queue workers**
  - A worker loaded the managed-key index once, so `flushPatterns()` and model invalidation in its jobs missed keys added later by other processes. Workers now refresh it before each job (see `refreshState()`).
  - A refresh job queued without a namespace now runs without one, instead of in whatever namespace an earlier job left active.
- **Events.** `KeyWritten` now carries the tags the write used; they were always `[]`. `forever()`, `rememberForever()`, a successful `add()`, and `flexible()` regenerations now dispatch it as well. Events stay disabled by default.
- **Circuit breaker.** Reading the state (`getState()`, `getStats()`, the dashboard) after the recovery timeout now restarts the success count, as `isAvailable()` does. A breaker that had re-opened could otherwise close after a single success.
- **Stampede protection**
  - `rememberWithStampedeProtection()` no longer refreshes about 37% of reads right after a write. The XFetch check used the whole TTL as the regeneration time. It now uses the measured regeneration time, recorded as `delta` in `_sc_meta:{key}`, so early refreshes happen just before expiry.
  - The early-refresh rate limit only covers the regeneration, rather than blocking the next early refresh for a whole TTL, and its key is namespaced.

### Changed
- **Upgrade note — `tags(...)->flush()`.** It now removes only the entries under those tags (with their chunks and metadata), like `flushTags()` and Laravel's `Cache::tags(...)->flush()`. It used to clear the **entire cache store**, which is also what the full documentation before 1.15 showed it doing for tagged entries. Only a `flush()` that follows `tags()` on the same instance, with no other cache call in between, is affected, and `tags([])->flush()` removes nothing. A plain `flush()`, and a `flush()` after any other cache call (a tagged lookup leaves its tags pending, as in 1.14), still clear the whole store. The facade resolves one shared instance, so tags left unused (for example, `SmartCache::tags('t')->put('k', $service->build())` where `build()` throws) make a later `SmartCache::flush()` in the same request flush only `t`. This is the same pending-tags behavior that has always made such tags apply to the next write. Pending tags are cleared at the end of every request and before each queued job.
- **Upgrade note — `smart-cache:clear --force` on Redis.** Because scanned keys now match, `--force` now does what it describes on Redis for the first time: it deletes every key in that cache store that SmartCache does not manage, except SmartCache's own `_sc_*` keys. That is roughly what `php artisan cache:clear` does for the store. Keys of other subsystems that share the connection without the cache prefix are not touched. Review what shares the store, such as `illuminate:queue:restart` (deleting it restarts queue workers once) or scheduler mutexes, before running it. `status --force` and `audit` are read-only and now report real results.
- **Upgrade note — chunk storage.** New chunked entries use versioned chunk keys, and chunked writes, plus array or collection writes without expiry, read the entry they replace once (a small wrapper when it was chunked). During a rolling deploy, 1.15 and 1.16 read each other's entries. Two side effects last until every server runs 1.16:
  - A 1.15 server that overwrites a key written by 1.16 leaves the 1.16 chunks in place until their TTL. Entries stored without expiry are registered, so `smart-cache:cleanup-chunks` removes them.
  - A 1.15 server that reads a chunked key at the moment a 1.16 server rewrites it can find the old chunks already gone. With `self_healing` enabled (the default) it evicts the entry, so the next read regenerates it. With `self_healing` disabled and `fallback.enabled` on, that read returns the internal `_sc_chunked` wrapper. In the same race, a 1.15-only fleet silently returned rows from both versions. 1.16 servers re-read the entry instead. If your fleet disables self-healing, keep the mixed-version window short.
- **Upgrade note — lazy loading.** With `strategies.chunking.lazy_loading` enabled, a missing chunk throws a `RuntimeException` while the collection is read, instead of silently yielding `null` items. Read the collection soon after `get()`, or call `materialize()`.
- **Upgrade note — stampede protection.** `RateLimiter::shouldRefreshProbabilistically()` gained an optional `$delta` argument and returns `true` far less often; called without it, it assumes one second (or a tenth of the TTL). Expect fewer early refreshes from `rememberWithStampedeProtection()`; the callback still runs at most once per expiry.
- `persistManagedKeys()` takes the metadata lock and reads the index before writing it: one extra read, at most once every 10 index changes and at the end of a request. When this instance persists the index, the keys it rewrote are re-added if another process removed them meanwhile. Metadata locks now retry every 25 ms instead of Laravel's default 250 ms. They remain best-effort: when a lock cannot be acquired within `metadata_lock.wait`, the update runs without it, as before.
- Under a namespace, pattern and model invalidation remove more keys (the namespace's matches), never fewer.
- Under a namespace, `dependsOn()` stores each edge twice in `_sc_dependencies`: as before, and with the namespace on both ends. The un-namespaced edge is still written and followed, so 1.15 servers keep invalidating during a rolling deploy, and dependencies declared before the upgrade keep working. Edges declared before the upgrade gain the per-tenant copy when they are declared again.

### Documentation
- Documented native memoization, `tags(...)->flush()`, the chunk lifecycle and registry, enum keys, the typed getters, the queue-worker refresh, and the new upgrade notes in the README and full documentation.
- The full documentation said orphan chunks are cleaned up automatically "when a chunked key expires and is accessed"; they are not. It now describes what the registry and `smart-cache:cleanup-chunks` cover.
- `SECURITY.md` now lists 1.16.x as the maintained release line.

## [1.15.0] - 2026-10-05

### Added
- `SmartCache::rememberWithLock($key, $ttl, $callback, $lockSeconds = 10, $waitSeconds = 10)` — single-flight regeneration for cold or freshly invalidated keys. On a cache miss the callback runs under an atomic lock, so one process rebuilds the value while concurrent requests wait and then read it, instead of every request hitting the database or upstream API at once. Waiting requests re-check the cache every 100 ms and return as soon as the value is stored. Cache hits never touch the lock. When the store does not implement `LockProvider`, the lock backend is unavailable, or the wait times out, the callback runs without the lock, so the method never fails where `remember()` would succeed. On the database cache driver, locks need the `cache_locks` table that `php artisan cache:table` creates; without it every miss falls back and logs a warning. The method is added to the concrete class and facade only; the `SmartCache` contract is unchanged.
- Opt-in scoped tags: `smart-cache.tags.scoped` (default `false`). `tags()` returns the same instance, and its tags stay active until a write uses them, so `SmartCache::tags('users')->get('missing')` followed by `put('site_settings', ...)` tags `site_settings`, and `flushTags('users')` then removes it. That only causes extra invalidation, never stale data, and it is unchanged by default: **with `scoped = false`, tags behave exactly as in 1.14.** Setting `scoped = true`, recommended for new applications, changes four things:
  - After a tagged lookup (a `get()` miss, `has()`, `forget()`, ...), the tags only apply to a later write of a key looked up while they were pending. The "read, then write on a miss" pattern (`$cache = SmartCache::tags('users'); $cache->get($key) ?? $cache->put($key, ...)`) and repeated `tags()` calls with the same list stay tagged.
  - Cache calls inside a `remember*()` callback do not use up the outer tags.
  - `swr()` / `stale()` / `refreshAhead()` / `flexible()` tag the key they regenerate.
  - `store()` / `memo()` called right after `tags()` hand the tags to the instance they return.
  In this mode, compute a value before calling `tags()`: a cache lookup inside `tags('t')->put('k', $service->build())` runs after `tags()` and would keep `k` from being tagged. Alternatively, use `tags('t')->remember()`. Tags that a subclass assigns to the protected `$activeTags` directly keep the 1.14 behavior, unless the list equals one that a pending `tags()` call set.
- The `OptimizationApplied` event is now dispatched when a compression, chunking, encryption, or serialization strategy is applied. It was documented and configurable (`events.dispatch.optimization_applied`) but never fired. Events stay disabled by default (`events.enabled = false`). Because measuring the two sizes costs a `serialize()` each, the event is only built and dispatched when a listener for it is registered or events are faked with `Event::fake()`; an application test that mocks the `Event` facade sees no new calls.

### Security
- Values matched by the encryption strategy are now always encrypted. Only one strategy is applied per value and encryption was registered after chunking and compression, so a matching value large enough to be chunked or compressed was stored as plain chunks or plain gzip, readable without the application key. Encryption is now tried first.
- Encryption `keys` and `patterns` now match keys written under a namespace. The strategy compared them against the namespaced key (`tenant:user_token_1`), so `namespace()` silently disabled encryption for every configured key.
- `asyncSwr()` / `refreshAsync()` no longer write a tenant's refreshed value to the global key. The queued job received the key without its namespace and the queue worker has none, so `namespace('tenant')->asyncSwr('feed', ...)` refreshed `feed` instead of `tenant:feed`. The job now carries the namespace, runs both the refresh callback and the write under it (so a callback that reads through SmartCache sees the tenant's data), and restores the caller's namespace afterwards, including on the sync queue.
- `memo()` now keeps the active namespace (and the jitter, circuit-breaker, and cost-aware settings), matching `store()`. `namespace('tenant')->memo()->get('key')` previously read the global `key`.

### Fixed
- Redis key enumeration for `smart-cache:clear --force`, `smart-cache:status --force`, and `smart-cache:audit` returned no keys on phpredis, a regression in 1.14.0. phpredis treats an integer `0` cursor as "iteration finished", so the scan stopped before it started. The scan now starts from the same cursor Laravel's own `RedisStore` uses (`null` on phpredis 6.1+, `'0'` otherwise).
- Arrays with only a few top-level keys are compressed once they pass the compression threshold. `CompressionStrategy` estimated an array's size as 50 bytes per top-level item, so a typical API payload such as `['data' => $rows, 'meta' => $meta]`, or a model's `toArray()` with a large relation, was never compressed however large it was, while long lists of tiny scalars below the threshold were. An array is now skipped only after its serialized size has been measured; a quick sample of a few entries, keys included, is used only to confirm that a long array is well above the threshold. A value that cannot be serialized is no longer compressed instead of throwing from the size check.
- A failed `add()` no longer corrupts an existing chunked entry. Chunk keys are derived from the cache key and were written before the atomic add was attempted, so `add()` on a live chunked key overwrote part of its data even though it returned `false`. For array and collection values, `add()` now returns `false` before optimizing when the key already exists. This adds one existence check to `add()` calls with an array or collection value; scalar values are unaffected.
- An entry that can no longer be decrypted (for example after an `APP_KEY` rotation) is treated as corrupted: self-healing evicts it and `remember()` regenerates it. It was previously served as a cached `null`, permanently for `rememberForever()` entries.
- A corrupted adaptive-compression entry is treated as corrupted too, instead of being served as `false` or garbage. `AdaptiveCompressionStrategy` now uses the validated decoder of `CompressionStrategy`, which shares its storage format.
- Chunked collections keep their class. An `Eloquent\Collection` large enough to be chunked came back as a plain `Support\Collection`, so `load()`, `modelKeys()`, and type hints broke above roughly 1,000 rows. Entries written by earlier releases still restore as `Support\Collection`. With `strategies.chunking.lazy_loading` enabled, chunked values are still returned as a `LazyChunkedCollection`.
- Model wildcard invalidation (`invalidatesPatterns()` / `cacheInvalidation()['patterns']`) no longer applies the active namespace twice, the same defect 1.14.0 fixed in `CacheInvalidationService`.
- `withFallback()` now calls a `Closure` fallback and returns its result, as documented, instead of returning the `Closure` itself. The public `CircuitBreaker::execute()` and `executeWithFallback()` methods resolve a `Closure` fallback the same way; any other fallback value is still returned as-is.
- `get()` and `pull()` now call a `Closure` default on a miss, like Laravel's `Repository`. `get(array)` and `put(array, $ttl)` now behave like `many()` and `putMany()` instead of raising "Array to string conversion", and `many()` accepts `['key' => $default]` pairs. `MemoizedCacheDriver::getMultiple()` now honours its `$default`.
- `asyncSwr()` no longer queues a refresh job on every request during the stale window. The freshness timestamp was not updated after a refresh was queued, so every request between `$ttl` and `$staleTtl` queued another job; it is now reset when the refresh is queued. Requests that see the entry go stale at the same moment can still each queue one. If queueing the refresh fails, the stale timestamp is put back so the next request retries.
- The `smart-cache.drivers.*` settings now apply. The cache driver was derived from the store class as `redis_`, `memcached_`, or `dynamo_db_` (with a trailing underscore), so per-driver `compression` / `chunking` settings never matched, `strategies.chunking.smart_sizing` never applied the Memcached (1 MB) and DynamoDB (400 KB) item limits, and `circuit_breaker.shared` used the key `_sc_circuit_breaker:redis_`. The driver is now Laravel's driver name (`redis`, `memcached`, `dynamodb`, ...).
- `monitoring.recent_entries_limit` is now honoured; the recent-operations list was hard-coded to 100 entries (still the default).

### Changed
- **Upgrade note — encryption.** If encryption is enabled, matching values that used to be compressed or chunked are now stored as one encrypted entry, which is larger than the compressed form. Check payload sizes on stores with item limits (Memcached 1 MB, DynamoDB 400 KB). Existing entries remain readable and are encrypted on their next write. Installations without encryption are unaffected.
- **Upgrade note — compression.** Large arrays with few top-level keys are now compressed on write, and small many-item arrays below the threshold no longer are. To decide this reliably, writing an array that chunking does not split now serializes it once to measure it, unless a quick sample already shows it is well above the threshold; this adds roughly 0.1 ms per 50 KB of array data to such writes. Existing entries are read unchanged; older releases can read entries written by this one.
- **Upgrade note — Memcached and `drivers.*`.** The shipped `drivers.memcached.compression` default changes from `false` to `true`. Because the setting never applied before, Memcached values have always been gzip-compressed by SmartCache, and the new default keeps exactly that, so installations using the shipped config see no change. If your application **published** `config/smart-cache.php`, its copy still says `'compression' => false` for Memcached, and that setting now takes effect: SmartCache stops compressing Memcached values and relies on php-memcached's own compression. Set it to `true` to keep the previous behavior. Any other `drivers.*` values you configured also take effect now. With `circuit_breaker.shared` enabled, the shared state key changes to `_sc_circuit_breaker:{driver}` without the trailing underscore, so the breaker starts closed once after upgrading.
- **Upgrade note — queued refreshes during a rolling deploy.** A refresh job queued by 1.15 but run by a 1.14 queue worker behaves as it did in 1.14 and writes the key without its namespace; the 1.14 worker ignores the job's new `namespace` property. Upgrade queue workers together with, or before, the servers that queue namespaced `asyncSwr()` / `refreshAsync()` refreshes.
- With `self_healing.enabled = false`, a failed decrypt now follows the same `fallback` path as the other strategies instead of returning `null`: with `fallback.enabled = true` (the default) the stored `['_sc_encrypted' => true, ...]` wrapper is returned to the caller, and with `fallback.enabled = false` the exception is thrown. Keep self-healing enabled (the default) to have such entries evicted and regenerated.

### Documentation
- Documented `rememberWithLock()` in the README and full documentation.
- The full documentation listed `SmartCache::getCompressionStats()`, `SmartCache::getSerializationStats()`, and `SmartCache::optimize()`, which do not exist on SmartCache and threw `BadMethodCallException`. The examples now call the statistics methods on the strategy instances that provide them.
- The README advised disabling compression "for those specific cache keys", which has no setting. It now points to `SmartCache::repository()`, which bypasses optimization.
- The full documentation's "Cache Tags" example called `SmartCache::tags(['users'])->flush()` to clear tagged entries. `flush()` clears the entire cache store; the example now uses `flushTags()`.
- Documented encryption precedence, namespace matching, how tags apply by default and with `tags.scoped`, the `cache_locks` table for `rememberWithLock()` on the database driver, and that `withFallback()` needs the circuit breaker enabled.
- Added `touch()` and `reset()` to the facade's `@method` annotations.
- `SECURITY.md` now lists 1.15.x as the maintained release line.

## [1.14.0] - 2026-09-01

### Security
- The dashboard's circuit-breaker state is now HTML-escaped before being rendered, and the CSS class derived from it is restricted to a safe character set. With `circuit_breaker.shared` enabled the state is read from the application cache, so any process able to write that entry could previously inject markup into the dashboard page.
- `CircuitBreaker` now validates the state it hydrates from shared cache against the three known values instead of trusting the stored payload, so a corrupted or hostile entry cannot put the breaker into an unrecognised state.
- Every dashboard endpoint re-checks `smart-cache.dashboard.enabled` at request time and returns 404 when it is off. Routes are registered from the service provider, so a `route:cache` taken while the dashboard was enabled baked them into the compiled route file and left all five endpoints live after the setting was switched back off. Dashboard route registration now also honours `routesAreCached()`, matching Laravel's own `loadRoutesFrom()`.
- Dashboard route registration guards `routesAreCached()` behind an `instanceof CachesRoutes` check, matching Laravel's own `loadRoutesFrom()`. `routesAreCached()` is not declared on the `Application` contract, so calling it unconditionally would raise `Error: Call to undefined method` on any container that does not implement `CachesRoutes` (Lumen, custom kernels).
- `smart-cache:clear --force`, `smart-cache:status --force`, and `smart-cache:audit` enumerate Redis keys with `SCAN` instead of `KEYS *`. `KEYS` is O(N) and runs to completion on Redis' single-threaded event loop, stalling every other client for the duration of the sweep. PhpRedis and Predis cluster connections scan every exposed node and merge the results. If a driver cannot provide a complete, usable `SCAN`, the command fails that optional enumeration safely and keeps its existing managed-key behavior; it never falls back to `KEYS` or acts on a partial result.
- Documented that `dashboard.middleware` ships as `['web']` — session and CSRF handling, but **no authentication** — in the README, the full documentation, and the published config file. The endpoints expose the managed cache key list and internal metrics. The default is unchanged so existing deployments keep working; add `auth` (and a gate) before exposing the dashboard.

### Fixed
- Write deduplication (Cache DNA, enabled by default) no longer shortens an entry's lifetime. Re-writing a key with unchanged content skipped the store write entirely, so the expiry was never extended and the value disappeared at the *first* write's TTL — breaking every "refresh this key on each pass" pattern. The `_sc_dna:{key}` record now stores the absolute expiry alongside the `xxh128` hash, and a write is skipped only when the stored entry provably outlives the newly requested window. Records written by earlier releases are a bare hash string; they are read safely, never treated as a skip signal, and are upgraded in place on the next write.
- Write deduplication could serve a stale value. `forever()`, `add()`, `increment()`, `decrement()`, `touch()`, and the SWR regeneration path (`flexible()`, `swr()`, `stale()`, `refreshAhead()`) changed a value or its expiry without maintaining the deduplication record, so a later `put()` compared against content or an expiry that was no longer in the store and skipped a write that was genuinely required. Writing value `Y`, then `forever()`-ing `X`, then writing `Y` again left `X` in the cache while `put()` reported success. All of these paths now invalidate the record.
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
- `healthCheckAndCleanup()` no longer iterates a key list captured before the expiry sweep, and malformed chunk wrappers can now be removed without `forget()` trying to iterate a missing or non-array `chunk_keys` value.
- The dashboard's "Hit Rate" card always displayed `N/A`. It read a top-level `hit_rate` key, but `getPerformanceMetrics()` reports the value as `cache_efficiency.hit_ratio`. The card now shows the real figure; the old key is still honoured for hand-built payloads.

### Changed
- Expect the `cache_write_dedup` performance counter to fall sharply after upgrading. Writes are now skipped only when the stored entry already outlives the requested TTL, instead of on any content match, so far fewer writes qualify. This is the intended trade-off: the previous counter was inflated by skips that were silently shortening cache lifetimes. Existing `_sc_dna:*` entries mismatch once after upgrade and are transparently rewritten; during a rolling deploy old and new code interoperate safely, with deduplication simply disabled for keys whose record was last written by the other version.
- `putWithJitter()` and `rememberWithJitter()` now always apply the percentage they are given exactly once. They previously routed through `applyJitter()`, which is gated on `smart-cache.jitter.enabled` — disabled by default — so both methods silently stored values with an unmodified TTL when global jitter was off and applied jitter twice when it was on. Calling them is the opt-in; `applyJitter()` keeps its existing flag-gated behavior for direct callers, and the fluent `withJitter()` modifier is unchanged.

### Changed (internal)
- `ClearCommand`, `StatusCommand`, and `AuditCommand` now share a `SmartCache\Console\Concerns\EnumeratesCacheKeys` trait instead of carrying separate key-enumeration helpers. No command signature or output changed.
- New implementation-only helpers are private rather than extending the subclass API, avoiding method or property collisions in applications that already extend SmartCache internals. Existing public and protected signatures are unchanged.

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

[1.17.0]: https://github.com/iazaran/smart-cache/compare/1.16.0...1.17.0
[1.16.0]: https://github.com/iazaran/smart-cache/compare/1.15.0...1.16.0
[1.15.0]: https://github.com/iazaran/smart-cache/compare/1.14.0...1.15.0
[1.14.0]: https://github.com/iazaran/smart-cache/compare/1.13.2...1.14.0
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
