# Laravel SmartCache

**Laravel SmartCache** is a caching optimization package designed to enhance the way your Laravel application handles data caching. It intelligently manages large data sets by compressing, chunking, or applying other optimization strategies to keep your application performant and efficient.

## 🚀 Features

- 🔍 Auto-detects large cache payloads
- 📦 Compresses data before caching
- 🧩 Chunks large arrays or objects into manageable parts
- 🧠 Intelligent serialization
- ♻️ Seamless retrieval and reconstruction
- ⚙️ Extensible strategy pattern for custom optimizations
- 🛡️ Optional fallback for incompatible drivers
- 🔄 Laravel-style helper function support

## 📦 Installation

```bash
composer require iazaran/smart-cache
```

> Laravel 8+ is supported. Tested with Redis and file cache drivers.

## ⚙️ Configuration

After installation, publish the config file:

```bash
php artisan vendor:publish --tag=smart-cache-config
```

The config file allows you to define thresholds, compression strategies, chunk sizes, and enabled features.

## 🧪 Usage

Use `SmartCache` just like Laravel's `Cache` facade:

```php
use SmartCache\Facades\SmartCache;

SmartCache::put('key', $largeData, now()->addMinutes(10));
$data = SmartCache::get('key');
```

Or use the global helper function, similar to Laravel's `cache()` helper:

```php
// Get a value
$value = smart_cache('key');

// Get with default value
$value = smart_cache('key', 'default');

// Store a value
smart_cache(['key' => $largeData], now()->addMinutes(10));

// Access the SmartCache instance
$cache = smart_cache();
```

Or inject it into your services:

```php
public function __construct(\SmartCache\Contracts\SmartCache $cache)
{
    $this->cache = $cache;
}
```

## 🔧 Optimization Strategies

SmartCache includes several strategies out of the box:

- **Compression**: Uses gzip or other drivers
- **Chunking**: Splits large data structures
- **Encoding**: Serializes data safely
- **Driver-Aware**: Avoids incompatible features based on driver

These strategies can be customized or extended by implementing `SmartCache\Contracts\OptimizationStrategy`.

## 📂 Example

```php
$data = range(1, 10000);

SmartCache::put('numbers', $data, 600);

// Behind the scenes:
// - Checks size
// - Compresses or chunks as needed
// - Stores metadata for retrieval

// Or with helper function
smart_cache(['numbers' => $data], 600);
```

## 🧰 Artisan Commands

```bash
php artisan smart-cache:clear
php artisan smart-cache:status
```

Built with ❤️ for developers who care about performance.
