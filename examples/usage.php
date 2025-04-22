<?php

// Basic usage with Facade
use SmartCache\Facades\SmartCache;

// Caching a large array that will be automatically compressed and/or chunked
$largeArray = range(1, 10000);
SmartCache::put('large-array', $largeArray, 600); // 10 minutes

// Later when retrieving
$array = SmartCache::get('large-array');

// Remember pattern
$users = SmartCache::remember('users', 3600, function () {
    // This expensive query will be cached and optimized automatically
    return User::with('roles', 'permissions', 'settings')
               ->whereHas('roles')
               ->get();
});

// Dependency Injection example
class UserService
{
    protected $cache;
    
    public function __construct(\SmartCache\Contracts\SmartCache $cache)
    {
        $this->cache = $cache;
    }
    
    public function getAllUsers()
    {
        return $this->cache->remember('all_users', 3600, function () {
            return User::with('relations')->get();
        });
    }
}

// Clearing specific keys
SmartCache::forget('large-array');

// Clearing all SmartCache managed keys
// This is useful when you want to clear only SmartCache items without affecting other cache
SmartCache::clear(); 