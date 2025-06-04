# Laravel SmartCache - Intelligent Caching Optimization Package

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)

**Laravel SmartCache** is a powerful caching optimization package designed to enhance the way your Laravel application handles data caching. It intelligently manages large data sets by compressing, chunking, or applying other optimization strategies to keep your application performant and efficient.

Perfect for **Laravel developers** looking to optimize **cache performance**, reduce **memory usage**, and improve **application speed** with intelligent **data compression** and **cache management**.

## 🚀 Features

- 🔍 **Auto-detects large cache payloads** - Automatically identifies when optimization is needed
- 📦 **Compresses data before caching** - Reduces storage requirements with gzip compression
- 🧩 **Chunks large arrays or objects** into manageable parts for better performance
- 🧠 **Intelligent serialization** - Advanced data serialization techniques
- ♻️ **Seamless retrieval and reconstruction** - Transparent data recovery
- ⚙️ **Extensible strategy pattern** for custom optimizations
- 🛡️ **Optional fallback** for incompatible drivers
- 🔄 **Laravel-style helper function** support
- 🎯 **Redis and file cache driver** optimization
- 📊 **Performance monitoring** and cache statistics

## 📦 Installation

Install the package via Composer:

```bash
composer require iazaran/smart-cache
```

> **Requirements:** Laravel 8+ is supported. Tested with Redis and file cache drivers.

## ⚙️ Configuration

After installation, publish the config file:

```bash
php artisan vendor:publish --tag=smart-cache-config
```

The config file allows you to define thresholds, compression strategies, chunk sizes, and enabled features.

## 🧪 Usage

### Basic Usage with SmartCache Facade

Use `SmartCache` just like Laravel's `Cache` facade:

```php
use SmartCache\Facades\SmartCache;

// Store large data with automatic optimization
SmartCache::put('user_data', $largeUserArray, now()->addMinutes(10));

// Retrieve data seamlessly
$userData = SmartCache::get('user_data');
```

### Helper Function Usage

Or use the global helper function, similar to Laravel's `cache()` helper:

```php
// Get a value
$value = smart_cache('cache_key');

// Get with default value
$value = smart_cache('cache_key', 'default_value');

// Store a value with automatic optimization
smart_cache(['large_dataset' => $bigArray], now()->addMinutes(10));

// Access the SmartCache instance
$cache = smart_cache();
```

### Dependency Injection

Or inject it into your services:

```php
public function __construct(\SmartCache\Contracts\SmartCache $cache)
{
    $this->cache = $cache;
}
```

## 🔧 Optimization Strategies

SmartCache includes several **cache optimization strategies** out of the box:

- **Compression**: Uses gzip or other compression drivers to reduce cache size
- **Chunking**: Splits large data structures into manageable chunks
- **Encoding**: Serializes data safely for different cache drivers
- **Driver-Aware**: Avoids incompatible features based on your cache driver

These strategies can be customized or extended by implementing `SmartCache\Contracts\OptimizationStrategy`.

## 📂 Example: Large Dataset Caching

```php
// Example: Caching large API response data
$apiData = range(1, 10000); // Large dataset
$complexObject = [
    'users' => $userCollection,
    'metadata' => $metadataArray,
    'statistics' => $statsData
];

// SmartCache automatically optimizes storage
SmartCache::put('api_response', $complexObject, 600);

// Behind the scenes:
// - Checks data size automatically
// - Compresses or chunks as needed
// - Stores optimization metadata for retrieval
// - Ensures fast reconstruction

// Retrieve optimized data
$retrievedData = SmartCache::get('api_response');

// Or with helper function
smart_cache(['api_response' => $complexObject], 600);
```

## 🧰 Artisan Commands

SmartCache provides helpful Artisan commands for **cache management**:

```bash
# Clear SmartCache data
php artisan smart-cache:clear

# Check cache status and statistics
php artisan smart-cache:status
```

## 🎯 Use Cases

- **Large API response caching** - Optimize storage of external API data
- **Database query result caching** - Cache complex query results efficiently
- **Session data optimization** - Reduce session storage requirements
- **File-based cache optimization** - Improve file cache performance
- **Redis memory optimization** - Reduce Redis memory usage
- **High-traffic applications** - Improve performance under load

## 📊 Performance Benefits

- **Up to 70% reduction** in cache storage size
- **Faster cache retrieval** for large datasets
- **Reduced memory usage** in Redis and other drivers
- **Improved application response times**
- **Better resource utilization**

## 🔧 Supported Cache Drivers

- ✅ **Redis** - Full feature support with compression and chunking
- ✅ **File Cache** - Optimized file-based caching
- ✅ **Database** - Database cache driver optimization
- ✅ **Array** - In-memory cache optimization
- ⚠️ **Memcached** - Basic support (limited chunking)

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details on:

- Reporting bugs
- Suggesting features
- Submitting pull requests
- Code style guidelines

## 📄 License

Laravel SmartCache is open-sourced software licensed under the [MIT license](LICENSE).

## 🔗 Links

- **GitHub Repository**: [https://github.com/iazaran/smart-cache](https://github.com/iazaran/smart-cache)
- **Packagist Package**: [https://packagist.org/packages/iazaran/smart-cache](https://packagist.org/packages/iazaran/smart-cache)
- **Documentation**: [https://iazaran.github.io/smart-cache/](https://iazaran.github.io/smart-cache/)

---

Built with ❤️ for developers who care about **Laravel performance optimization** and **efficient caching strategies**.

**Keywords**: Laravel caching, PHP cache optimization, Redis optimization, cache compression, Laravel performance, data chunking, cache management, Laravel package
