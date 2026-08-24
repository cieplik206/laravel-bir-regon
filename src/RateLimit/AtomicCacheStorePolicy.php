<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\RateLimit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;

final class AtomicCacheStorePolicy
{
    /** @var list<class-string<Store>> */
    private const SUPPORTED_STORES = [
        ArrayStore::class,
        DatabaseStore::class,
        FileStore::class,
        MemcachedStore::class,
        RedisStore::class,
    ];

    public static function supports(Store $store): bool
    {
        return in_array($store::class, self::SUPPORTED_STORES, true);
    }
}
