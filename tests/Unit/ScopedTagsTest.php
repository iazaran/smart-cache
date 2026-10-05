<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\SmartCache;
use SmartCache\Tests\TestCase;

/**
 * Opt-in scoped tags (smart-cache.tags.scoped = true, since 1.15.0).
 *
 * After a tagged lookup, the tags only apply to a later write of a key looked
 * up while they were pending, so they cannot tag an unrelated write. The
 * default keeps the 1.14 behaviour; see V115FixesTest.
 */
class ScopedTagsTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('smart-cache.tags.scoped', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->smartCache = $this->app->make(SmartCacheContract::class);
    }

    protected function rawStore()
    {
        return $this->app['cache']->store();
    }

    public function test_tagged_miss_does_not_tag_an_unrelated_write(): void
    {
        $this->smartCache->tags(['users'])->get('missing');
        $this->smartCache->put('site_settings', 'kept', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertSame('kept', $this->smartCache->get('site_settings'));
    }

    public function test_tagged_miss_still_tags_the_write_of_the_same_key(): void
    {
        // The Laravel-style pattern: read through the tagged instance, write on a miss.
        $cache = $this->smartCache->tags(['users']);
        $this->assertNull($cache->get('user_1'));
        $cache->put('user_1', 'cached', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertFalse($this->smartCache->has('user_1'));
    }

    public function test_tagged_has_then_put_of_the_same_key_is_tagged(): void
    {
        $cache = $this->smartCache->tags(['users']);
        $this->assertFalse($cache->has('user_2'));
        $cache->put('user_2', 'cached', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertFalse($this->smartCache->has('user_2'));
    }

    public function test_repeated_tags_calls_keep_every_looked_up_key_tagged(): void
    {
        // Look up several keys through the facade, then write the misses.
        $this->assertNull($this->smartCache->tags(['users'])->get('user_a'));
        $this->assertNull($this->smartCache->tags(['users'])->get('user_b'));
        $this->smartCache->put('user_a', 'cached', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertFalse($this->smartCache->has('user_a'));
    }

    public function test_tags_set_right_before_a_write_apply(): void
    {
        $this->smartCache->tags(['users'])->get('missing');
        $this->smartCache->tags(['users'])->put('user_5', 'cached', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertFalse($this->smartCache->has('user_5'));
    }

    public function test_subclass_setting_active_tags_directly_keeps_the_previous_behaviour(): void
    {
        $cache = new class($this->rawStore(), $this->app['cache'], $this->app['config']) extends SmartCache {
            public function withTags(array $tags): static
            {
                $this->activeTags = $tags;
                return $this;
            }
        };

        $this->assertNull($cache->withTags(['t'])->get('a'));
        $cache->withTags(['t'])->put('b', 'cached', 60);

        $cache->flushTags(['t']);

        $this->assertFalse($cache->has('b'));
    }

    public function test_rejected_remember_if_does_not_tag_the_next_write(): void
    {
        $this->smartCache->tags(['users'])->rememberIf('empty_result', 60, fn () => [], fn ($value) => $value !== []);
        $this->smartCache->put('site_settings', 'kept', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertSame('kept', $this->smartCache->get('site_settings'));
    }

    public function test_tagged_swr_tags_its_key_and_does_not_leak(): void
    {
        $this->smartCache->tags(['feeds'])->swr('feed', fn () => 'fresh', 60, 120);
        $this->smartCache->put('site_settings', 'kept', 60);

        $this->smartCache->flushTags(['feeds']);

        $this->assertFalse($this->smartCache->has('feed'));
        $this->assertSame('kept', $this->smartCache->get('site_settings'));
    }

    public function test_tags_move_to_the_instance_returned_by_store(): void
    {
        $this->smartCache->tags(['users'])->store('array')->put('user_3', 'cached', 60);
        $this->smartCache->put('site_settings', 'kept', 60);

        $this->smartCache->flushTags(['users']);

        $this->assertFalse($this->smartCache->has('user_3'));
        $this->assertSame('kept', $this->smartCache->get('site_settings'));
    }

    public function test_tagged_refresh_async_does_not_tag_the_next_write(): void
    {
        Bus::fake();

        $this->smartCache->tags(['feeds'])->refreshAsync('feed', RefreshFeed::class, 600);
        $this->smartCache->put('site_settings', 'kept', 60);

        $this->smartCache->flushTags(['feeds']);

        $this->assertSame('kept', $this->smartCache->get('site_settings'));
        Bus::assertDispatched(BackgroundCacheRefreshJob::class);
    }

    public function test_nested_lookups_in_a_tagged_remember_callback_keep_the_outer_tag(): void
    {
        $this->smartCache->tags(['t'])->remember('report', 60, function () {
            $this->smartCache->memo()->get('piece');
            $this->smartCache->store('array')->get('other_piece');
            $this->smartCache->swr('fragment', fn () => 'f', 60, 120);
            $this->smartCache->has('flag');

            return 'value';
        });

        $this->smartCache->flushTags(['t']);

        $this->assertFalse($this->smartCache->has('report'));
    }

    public function test_nested_remember_in_a_tagged_callback_is_not_tagged(): void
    {
        $this->smartCache->tags(['t'])->rememberWithLock('report', 60, function () {
            $this->smartCache->memo()->remember('settings', 60, fn () => 'settings');

            return 'value';
        });

        $this->smartCache->flushTags(['t']);

        $this->assertFalse($this->smartCache->has('report'));
        $this->assertSame('settings', $this->smartCache->get('settings'));
    }
}
