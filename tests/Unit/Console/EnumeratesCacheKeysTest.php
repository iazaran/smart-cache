<?php

namespace SmartCache\Tests\Unit\Console;

use SmartCache\Console\Concerns\EnumeratesCacheKeys;
use SmartCache\Tests\TestCase;

/**
 * The Redis key sweep must never fall back to KEYS *, which blocks the server's
 * single-threaded event loop for the whole scan.
 */
class EnumeratesCacheKeysTest extends TestCase
{
    protected function subject(): object
    {
        return new class {
            use EnumeratesCacheKeys;

            public function scan(object $connection): array
            {
                return $this->scanRedisKeys($connection);
            }
        };
    }

    public function test_scans_a_phpredis_style_connection_without_calling_keys(): void
    {
        // Laravel's PhpRedisConnection::scan() returns [cursor, keys] and false
        // once the iteration is exhausted.
        $connection = new class {
            public bool $keysCalled = false;
            private array $pages = [
                [2, ['a', 'b']],
                [5, ['c']],
                [0, ['d', 'b']],   // SCAN may repeat a key
            ];
            private int $call = 0;

            public function scan($cursor, $options = [])
            {
                if ($this->call >= count($this->pages)) {
                    return false;
                }

                return $this->pages[$this->call++];
            }

            public function keys($pattern): array
            {
                $this->keysCalled = true;
                return ['SHOULD-NOT-BE-USED'];
            }
        };

        $keys = $this->subject()->scan($connection);

        sort($keys);
        $this->assertSame(['a', 'b', 'c', 'd'], $keys);
        $this->assertFalse($connection->keysCalled, 'KEYS * must not be used when SCAN works.');
    }

    public function test_scans_a_predis_style_connection_with_string_cursors(): void
    {
        // Predis returns the cursor as a string.
        $connection = new class {
            public bool $keysCalled = false;
            private array $pages = [
                ['12', ['x', 'y']],
                ['0', ['z']],
            ];
            private int $call = 0;

            public function scan($cursor, $options = null)
            {
                return $this->pages[$this->call++] ?? ['0', []];
            }

            public function keys($pattern): array
            {
                $this->keysCalled = true;
                return ['SHOULD-NOT-BE-USED'];
            }
        };

        $keys = $this->subject()->scan($connection);

        sort($keys);
        $this->assertSame(['x', 'y', 'z'], $keys);
        $this->assertFalse($connection->keysCalled);
    }

    public function test_scans_every_phpredis_cluster_master_without_calling_keys(): void
    {
        $connection = new PhpRedisClusterConnectionStub();

        $keys = $this->subject()->scan($connection);

        sort($keys);
        $this->assertSame(['a', 'b', 'c'], $keys);
        $this->assertSame(['node-a', 'node-b'], $connection->client->scannedNodes);
        $this->assertFalse($connection->keysCalled);
    }

    public function test_scans_every_predis_cluster_node_without_calling_keys(): void
    {
        $connection = new PredisClusterConnectionStub();

        $keys = $this->subject()->scan($connection);

        sort($keys);
        $this->assertSame(['a', 'b', 'c'], $keys);
        $this->assertFalse($connection->keysCalled);
        $this->assertFalse($connection->nodes[0]->keysCalled);
        $this->assertFalse($connection->nodes[1]->keysCalled);
    }

    public function test_fails_safely_when_scan_is_unavailable(): void
    {
        $connection = new class {
            public bool $keysCalled = false;

            public function keys($pattern): array
            {
                $this->keysCalled = true;
                return ['fallback-key'];
            }
        };

        try {
            $this->subject()->scan($connection);
            $this->fail('A connection without SCAN must fail safely.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not support SCAN', $e->getMessage());
        }

        $this->assertFalse($connection->keysCalled);
    }

    public function test_fails_safely_when_scan_returns_an_unexpected_shape(): void
    {
        $connection = new class {
            public bool $keysCalled = false;

            public function scan($cursor, $options = [])
            {
                return 'nonsense';
            }

            public function keys($pattern): array
            {
                $this->keysCalled = true;
                return ['fallback-key'];
            }
        };

        try {
            $this->subject()->scan($connection);
            $this->fail('A malformed SCAN result must fail safely.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unexpected result', $e->getMessage());
        }

        $this->assertFalse($connection->keysCalled);
    }

    public function test_fails_safely_when_scan_throws(): void
    {
        $connection = new class {
            public bool $keysCalled = false;

            public function scan($cursor, $options = [])
            {
                throw new \RuntimeException('SCAN unsupported');
            }

            public function keys($pattern): array
            {
                $this->keysCalled = true;
                return ['fallback-key'];
            }
        };

        try {
            $this->subject()->scan($connection);
            $this->fail('A failing SCAN must fail safely.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SCAN unsupported', $e->getMessage());
        }

        $this->assertFalse($connection->keysCalled);
    }

    public function test_does_not_start_the_scan_from_an_integer_zero_cursor(): void
    {
        // phpredis treats an integer 0 cursor as "iteration finished" and returns
        // false straight away, so a scan started from 0 enumerated no keys at all.
        $connection = new class {
            public array $cursors = [];

            public function scan($cursor, $options = [])
            {
                $this->cursors[] = $cursor;

                if ($cursor === 0) {
                    return false;
                }

                return $cursor === 9 ? [0, ['c']] : [9, ['a', 'b']];
            }
        };

        $keys = $this->subject()->scan($connection);

        sort($keys);
        $this->assertSame(['a', 'b', 'c'], $keys);
        $this->assertNotSame(0, $connection->cursors[0]);
    }

    public function test_does_not_spin_forever_when_the_cursor_never_returns_to_zero(): void
    {
        $connection = new class {
            public int $calls = 0;

            public function scan($cursor, $options = [])
            {
                $this->calls++;
                return [7, ['k' . $this->calls]];   // cursor never reaches 0
            }

            public function keys($pattern): array
            {
                return [];
            }
        };

        try {
            $this->subject()->scan($connection);
            $this->fail('A non-terminating SCAN must fail safely.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not complete', $e->getMessage());
        }

        $this->assertSame(100000, $connection->calls);
    }
}

/**
 * Named to match the dispatch the trait performs on the connection class name,
 * mirroring Illuminate\Redis\Connections\PhpRedisClusterConnection.
 */
class PhpRedisClusterConnectionStub
{
    public bool $keysCalled = false;
    public PhpRedisClusterClientStub $client;

    public function __construct()
    {
        $this->client = new PhpRedisClusterClientStub();
    }

    public function isCluster(): bool
    {
        return true;
    }

    public function client(): object
    {
        return $this->client;
    }

    public function keys($pattern): array
    {
        $this->keysCalled = true;
        return ['SHOULD-NOT-BE-USED'];
    }
}

class PhpRedisClusterClientStub
{
    public array $scannedNodes = [];

    public function _masters(): array
    {
        return ['node-a', 'node-b'];
    }

    public function scan(&$cursor, $node, $pattern = '*', $count = 0): array
    {
        $this->scannedNodes[] = $node;
        $cursor = 0;

        return $node === 'node-a' ? ['a', 'b'] : ['b', 'c'];
    }
}

class PredisClusterConnectionStub
{
    public bool $keysCalled = false;
    public array $nodes;

    public function __construct()
    {
        $this->nodes = [
            new PredisClusterNodeStub(['a', 'b']),
            new PredisClusterNodeStub(['b', 'c']),
        ];
    }

    public function isCluster(): bool
    {
        return true;
    }

    public function client(): \Traversable
    {
        return new \ArrayIterator($this->nodes);
    }

    public function keys($pattern): array
    {
        $this->keysCalled = true;
        return ['SHOULD-NOT-BE-USED'];
    }
}

class PredisClusterNodeStub
{
    public bool $keysCalled = false;

    public function __construct(private array $keys)
    {
    }

    public function scan($cursor, $options = [])
    {
        return [0, $this->keys];
    }

    public function keys($pattern): array
    {
        $this->keysCalled = true;
        return ['SHOULD-NOT-BE-USED'];
    }
}
