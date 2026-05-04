<?php

namespace SmartCache\Tests\Unit\Strategies;

use SmartCache\Tests\TestCase;
use SmartCache\Strategies\SmartSerializationStrategy;

class SmartSerializationStrategyTest extends TestCase
{
    public function test_should_apply_returns_true()
    {
        // Use a low threshold to test with small data
        $strategy = new SmartSerializationStrategy('auto', true, 10);

        $this->assertTrue($strategy->shouldApply('any_value_that_is_long_enough'));
        $this->assertTrue($strategy->shouldApply(['array', 'with', 'multiple', 'elements']));
        $this->assertTrue($strategy->shouldApply((object) ['key' => 'value', 'another' => 'data']));

        // Test with default threshold (1024 bytes) - small values should return false
        $defaultStrategy = new SmartSerializationStrategy();
        $this->assertFalse($defaultStrategy->shouldApply('small'));

        // Large data should return true with default threshold
        $largeData = str_repeat('x', 2000);
        $this->assertTrue($defaultStrategy->shouldApply($largeData));
    }

    public function test_optimize_serializes_data()
    {
        $strategy = new SmartSerializationStrategy();

        $data = ['key' => 'value'];
        $optimized = $strategy->optimize($data);

        $this->assertIsArray($optimized);
        $this->assertTrue($optimized['_sc_serialized']);
        $this->assertArrayHasKey('method', $optimized);
        $this->assertArrayHasKey('data', $optimized);
    }

    public function test_restore_unserializes_data()
    {
        $strategy = new SmartSerializationStrategy();

        $originalData = ['key' => 'value', 'number' => 123];
        $optimized = $strategy->optimize($originalData);
        $restored = $strategy->restore($optimized);

        $this->assertEquals($originalData, $restored);
    }

    public function test_json_serialization_for_simple_data()
    {
        $strategy = new SmartSerializationStrategy('auto', true);

        $simpleData = ['key' => 'value', 'number' => 123, 'bool' => true];
        $optimized = $strategy->optimize($simpleData);

        $this->assertEquals('json', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertEquals($simpleData, $restored);
    }

    public function test_php_serialization_for_objects()
    {
        // Force PHP serialization mode
        $strategy = new SmartSerializationStrategy('php', false);

        // Use stdClass
        $object = new \stdClass();
        $object->property = 'value';
        $object->nested = new \stdClass();
        $object->nested->data = 'nested_value';

        $optimized = $strategy->optimize($object);

        // Should use PHP serialize
        $this->assertEquals('php', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertEquals($object->property, $restored->property);
        $this->assertEquals($object->nested->data, $restored->nested->data);
    }

    public function test_forced_json_serialization()
    {
        $strategy = new SmartSerializationStrategy('json', false);

        $data = ['key' => 'value'];
        $optimized = $strategy->optimize($data);

        $this->assertEquals('json', $optimized['method']);
    }

    public function test_forced_php_serialization()
    {
        $strategy = new SmartSerializationStrategy('php', false);

        $data = ['key' => 'value'];
        $optimized = $strategy->optimize($data);

        $this->assertEquals('php', $optimized['method']);
    }

    public function test_igbinary_serialization_if_available()
    {
        if (!function_exists('igbinary_serialize')) {
            $this->markTestSkipped('igbinary extension not available');
        }

        $strategy = new SmartSerializationStrategy('igbinary', false);

        $data = ['key' => 'value'];
        $optimized = $strategy->optimize($data);

        $this->assertEquals('igbinary', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertEquals($data, $restored);
    }

    public function test_get_identifier()
    {
        $strategy = new SmartSerializationStrategy();

        $this->assertEquals('smart_serialization', $strategy->getIdentifier());
    }

    public function test_serialization_stats()
    {
        $strategy = new SmartSerializationStrategy();

        $data = ['key' => 'value', 'number' => 123];
        $stats = $strategy->getSerializationStats($data);

        $this->assertArrayHasKey('json', $stats);
        $this->assertArrayHasKey('php', $stats);
        $this->assertArrayHasKey('recommended', $stats);
        $this->assertArrayHasKey('best_size', $stats);
    }

    public function test_json_is_more_compact_for_simple_data()
    {
        $strategy = new SmartSerializationStrategy();

        $simpleData = ['a' => 1, 'b' => 2, 'c' => 3];
        $stats = $strategy->getSerializationStats($simpleData);

        // JSON should be recommended for simple data
        $this->assertEquals('json', $stats['recommended']);
    }

    public function test_handles_large_arrays()
    {
        $strategy = new SmartSerializationStrategy();

        $largeArray = array_fill(0, 10000, 'test_data');
        $optimized = $strategy->optimize($largeArray);
        $restored = $strategy->restore($optimized);

        $this->assertCount(10000, $restored);
        $this->assertEquals($largeArray, $restored);
    }

    public function test_handles_nested_arrays()
    {
        $strategy = new SmartSerializationStrategy();

        $nestedData = [
            'level1' => [
                'level2' => [
                    'level3' => ['value' => 'deep']
                ]
            ]
        ];

        $optimized = $strategy->optimize($nestedData);
        $restored = $strategy->restore($optimized);

        $this->assertEquals($nestedData, $restored);
    }

    public function test_handles_mixed_types()
    {
        $strategy = new SmartSerializationStrategy();

        $mixedData = [
            'string' => 'value',
            'int' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
        ];

        $optimized = $strategy->optimize($mixedData);
        $restored = $strategy->restore($optimized);

        $this->assertEquals($mixedData, $restored);
    }

    public function test_json_preserves_float_zero_fraction()
    {
        $strategy = new SmartSerializationStrategy('auto', true);

        $data = ['amount' => 1.0];
        $optimized = $strategy->optimize($data);

        $this->assertEquals('json', $optimized['method']);
        $this->assertEquals($data, $strategy->restore($optimized));
    }

    public function test_fallback_to_php_for_unsupported_method()
    {
        $strategy = new SmartSerializationStrategy('igbinary', false);

        if (function_exists('igbinary_serialize')) {
            $this->markTestSkipped('igbinary is available, cannot test fallback');
        }

        $data = ['key' => 'value'];
        $optimized = $strategy->optimize($data);

        // Should fallback to PHP serialize
        $this->assertEquals('php', $optimized['method']);
    }

    public function test_restore_handles_non_serialized_data()
    {
        $strategy = new SmartSerializationStrategy();

        $plainData = 'not_serialized';
        $restored = $strategy->restore($plainData);

        $this->assertEquals($plainData, $restored);
    }

    public function test_restore_existing_json_payload_format_remains_compatible()
    {
        $strategy = new SmartSerializationStrategy();

        $payload = [
            '_sc_serialized' => true,
            'method' => 'json',
            'data' => '{"key":"value","number":123}',
        ];

        $this->assertSame(['key' => 'value', 'number' => 123], $strategy->restore($payload));
    }

    public function test_custom_object_falls_back_to_php_when_auto_detecting()
    {
        $strategy = new SmartSerializationStrategy('auto', true);

        // Create an instance of a named class (which json_encode handles as stdClass,
        // but our strategy explicitly prevents so it falls back to preserving the real class)
        $customObject = new \Exception('test');

        $optimized = $strategy->optimize($customObject);

        // Because json_encode loses class information, auto-detect should fall back
        // to PHP serialization (or igbinary if installed, so we check it's NOT json)
        $this->assertNotEquals('json', $optimized['method']);
        $this->assertTrue(in_array($optimized['method'], ['php', 'igbinary']));

        $restored = $strategy->restore($optimized);
        $this->assertEquals('test', $restored->getMessage());
    }

    public function test_stdclass_falls_back_to_php_when_json_would_change_type()
    {
        $strategy = new SmartSerializationStrategy('auto', true);

        $object = new \stdClass();
        $object->name = 'cached-object';

        $optimized = $strategy->optimize($object);

        $this->assertNotEquals('json', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertInstanceOf(\stdClass::class, $restored);
        $this->assertEquals('cached-object', $restored->name);
    }

    public function test_nested_object_falls_back_to_php_when_auto_detecting()
    {
        $strategy = new SmartSerializationStrategy('auto', true);

        $payload = ['items' => [new \Exception('nested')]];
        $optimized = $strategy->optimize($payload);

        $this->assertNotEquals('json', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertInstanceOf(\Exception::class, $restored['items'][0]);
        $this->assertEquals('nested', $restored['items'][0]->getMessage());
    }

    public function test_forced_json_falls_back_when_it_would_corrupt_value()
    {
        $strategy = new SmartSerializationStrategy('json', false);

        $object = new \stdClass();
        $object->name = 'forced-json-object';

        $optimized = $strategy->optimize($object);

        $this->assertEquals('php', $optimized['method']);

        $restored = $strategy->restore($optimized);
        $this->assertInstanceOf(\stdClass::class, $restored);
        $this->assertEquals('forced-json-object', $restored->name);
    }

    public function test_is_json_safe_does_not_emit_warning_for_top_level_resource()
    {
        $strategy = new SmartSerializationStrategy();
        $resource = fopen('php://memory', 'r');

        try {
            $errors = [];
            \set_error_handler(function ($errno, $errstr) use (&$errors) {
                $errors[] = $errstr;
                return true;
            });

            try {
                $result = $this->invokeIsJsonSafe($strategy, $resource);
            } finally {
                \restore_error_handler();
            }

            $this->assertFalse($result);
            $this->assertEmpty($errors, 'isJsonSafe should not emit warnings: ' . implode(', ', $errors));
        } finally {
            \fclose($resource);
        }
    }

    public function test_is_json_safe_does_not_emit_warning_for_top_level_closure()
    {
        $strategy = new SmartSerializationStrategy();
        $closure = static fn() => 1;

        $errors = [];
        \set_error_handler(function ($errno, $errstr) use (&$errors) {
            $errors[] = $errstr;
            return true;
        });

        try {
            $result = $this->invokeIsJsonSafe($strategy, $closure);
        } finally {
            \restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertEmpty($errors, 'isJsonSafe should not emit warnings: ' . implode(', ', $errors));
    }

    public function test_is_json_safe_does_not_emit_warning_for_nested_resource()
    {
        $strategy = new SmartSerializationStrategy();
        $resource = fopen('php://memory', 'r');

        try {
            $errors = [];
            \set_error_handler(function ($errno, $errstr) use (&$errors) {
                $errors[] = $errstr;
                return true;
            });

            try {
                $result = $this->invokeIsJsonSafe($strategy, ['handle' => $resource]);
            } finally {
                \restore_error_handler();
            }

            $this->assertFalse($result);
            $this->assertEmpty($errors, 'Nested resource should not emit warnings: ' . implode(', ', $errors));
        } finally {
            \fclose($resource);
        }
    }

    protected function invokeIsJsonSafe(SmartSerializationStrategy $strategy, mixed $value): bool
    {
        $reflection = new \ReflectionMethod($strategy, 'isJsonSafe');
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke($strategy, $value);
    }
}
