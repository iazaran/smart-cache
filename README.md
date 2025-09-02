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

SmartCache includes several **cache optimization strategies** that intelligently optimize your data:

### 🎯 Strategy Selection & Priority

SmartCache automatically selects the **best optimization strategy** for your data:

1. **Chunking Strategy** (Priority 1)
   - Applied to large arrays/collections with many items
   - Splits data into manageable chunks for better performance
   - Best for: Large datasets, API response arrays, database result sets

2. **Compression Strategy** (Priority 2)  
   - Applied to large strings, arrays, and objects
   - Uses gzip compression to reduce cache size
   - Best for: Text data, serialized objects, repetitive content

3. **No Optimization** (Default)
   - Small data stays unchanged for optimal performance
   - No overhead for data that doesn't benefit from optimization

### 🧠 Intelligent Strategy Application

Each strategy evaluates the **original data independently** to determine if it should apply:

- **Data Type Matching**: Chunking only applies to arrays/collections, compression works on all types
- **Size Thresholds**: Each strategy has configurable size thresholds
- **Driver Compatibility**: Strategies respect cache driver limitations
- **Performance Optimization**: Only one strategy is applied per value (no chaining overhead)

### 🛠️ Built-in Strategies

- **Compression**: Uses gzip compression with configurable levels (1-9)
- **Chunking**: Splits large arrays into configurable chunk sizes
- **Encoding**: Safe serialization for different cache drivers  
- **Driver-Aware**: Automatically adapts to your cache driver capabilities

### ⚙️ Extensible Architecture

Create custom strategies by implementing `SmartCache\Contracts\OptimizationStrategy`:

```php
class CustomStrategy implements OptimizationStrategy
{
    public function shouldApply(mixed $value, array $context = []): bool
    {
        // Your optimization criteria
    }
    
    public function optimize(mixed $value, array $context = []): mixed
    {
        // Your optimization logic
    }
    
    public function restore(mixed $value, array $context = []): mixed
    {
        // Your restoration logic
    }
}
```

## 📂 Example: Large Dataset Caching

```php
// Example: Caching large API response data
$apiData = range(1, 10000); // Large dataset
$complexObject = [
    'users' => $userCollection,
    'metadata' => $metadataArray,
    'statistics' => $statsData
];

// SmartCache automatically selects the best optimization
SmartCache::put('api_response', $complexObject, 600);

// Behind the scenes:
// - Evaluates all strategies against original data
// - Selects best strategy (chunking for large arrays, compression for others)
// - Applies single optimal transformation
// - Stores optimization metadata for retrieval
// - Ensures fast reconstruction

// Retrieve optimized data
$retrievedData = SmartCache::get('api_response');

// Or with helper function
smart_cache(['api_response' => $complexObject], 600);
```

## 🎯 Strategy Selection Examples

```php
// Large array with many items → Chunking applied
$manyUsers = User::all(); // 5000+ user records
SmartCache::put('all_users', $manyUsers); // → Chunked storage

// Large string content → Compression applied  
$largeText = file_get_contents('large_file.txt'); // 100KB text
SmartCache::put('file_content', $largeText); // → Compressed storage

// Medium array with large items → Compression applied
$reports = [ /* 50 items with large content each */ ];
SmartCache::put('reports', $reports); // → Compressed storage

// Small data → No optimization (fastest)
$config = ['setting' => 'value'];
SmartCache::put('config', $config); // → Stored as-is
```

## 🧰 Artisan Commands

SmartCache provides helpful Artisan commands for **cache management**:

```bash
# Clear only SmartCache managed items
php artisan smart-cache:clear

# Clear specific managed key  
php artisan smart-cache:clear my-key

# Force clear any key, even if not managed by SmartCache
php artisan smart-cache:clear my-non-managed-key --force

# Clear all managed keys + cleanup orphaned SmartCache keys
php artisan smart-cache:clear --force

# Check SmartCache status and statistics
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
