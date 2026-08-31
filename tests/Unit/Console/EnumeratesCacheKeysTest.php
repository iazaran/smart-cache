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

    public function test_falls_back_to_keys_when_scan_returns_an_unexpected_shape(): void
    {
        $connection = new class {
            public function scan($cursor, $options = [])
            {
                return 'nonsense';
            }

            public function keys($pattern): array
            {
                return ['fallback-key'];
            }
        };

        $this->assertSame(['fallback-key'], $this->subject()->scan($connection));
    }

    public function test_falls_back_to_keys_when_scan_throws(): void
    {
        $connection = new class {
            public function scan($cursor, $options = [])
            {
                throw new \RuntimeException('SCAN unsupported');
            }

            public function keys($pattern): array
            {
                return ['fallback-key'];
            }
        };

        $this->assertSame(['fallback-key'], $this->subject()->scan($connection));
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

        $keys = $this->subject()->scan($connection);

        $this->assertNotEmpty($keys);
        $this->assertLessThanOrEqual(100001, $connection->calls);
    }
}
