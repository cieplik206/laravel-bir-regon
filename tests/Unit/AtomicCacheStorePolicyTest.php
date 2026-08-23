<?php

declare(strict_types=1);

use cieplik206\BirRegon\RateLimit\AtomicCacheStorePolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\MemoizedStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

it('allows only Laravel stores with compatible atomic lock and state semantics', function (
    string $storeClass,
): void {
    if (! is_a($storeClass, Store::class, true)) {
        throw new RuntimeException('The cache fixture class must implement the Store contract.');
    }

    $store = (new ReflectionClass($storeClass))->newInstanceWithoutConstructor();

    expect(AtomicCacheStorePolicy::supports($store))->toBeTrue();
})->with([
    'array' => [ArrayStore::class],
    'database' => [DatabaseStore::class],
    'file' => [FileStore::class],
    'Memcached' => [MemcachedStore::class],
    'Redis' => [RedisStore::class],
]);

it('rejects Laravel wrappers and stores without compatible consistency guarantees', function (
    string $storeClass,
): void {
    if (! is_a($storeClass, Store::class, true)) {
        throw new RuntimeException('The cache fixture class must implement the Store contract.');
    }

    $store = (new ReflectionClass($storeClass))->newInstanceWithoutConstructor();

    expect(AtomicCacheStorePolicy::supports($store))->toBeFalse();
})->with([
    'DynamoDB eventually consistent reads' => [DynamoDbStore::class],
    'failover can split locks and state' => [FailoverStore::class],
    'memoized reads can be stale' => [MemoizedStore::class],
    'null store does not persist state' => [NullStore::class],
]);

it('rejects unknown lock providers by default', function (): void {
    $store = Mockery::mock(Store::class, LockProvider::class);

    if (! $store instanceof Store) {
        throw new RuntimeException('The custom cache fixture must implement the Store contract.');
    }

    expect(AtomicCacheStorePolicy::supports($store))->toBeFalse();
});

it('rejects subclasses of supported stores because they can replace lock or state semantics', function (): void {
    $store = new class extends ArrayStore {};

    expect(AtomicCacheStorePolicy::supports($store))->toBeFalse();
});
