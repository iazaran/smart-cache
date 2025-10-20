<?php

namespace SmartCache\Events;

class CacheMissed
{
    /**
     * The key that was requested.
     *
     * @var string
     */
    public string $key;

    /**
     * The tags associated with the key.
     *
     * @var array
     */
    public array $tags;

    /**
     * Create a new event instance.
     *
     * @param string $key
     * @param array $tags
     */
    public function __construct(string $key, array $tags = [])
    {
        $this->key = $key;
        $this->tags = $tags;
    }
}

