<?php

namespace SmartCache\Events;

class CacheHit
{
    /**
     * The key that was retrieved.
     *
     * @var string
     */
    public string $key;

    /**
     * The value that was retrieved.
     *
     * @var mixed
     */
    public mixed $value;

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
     * @param mixed $value
     * @param array $tags
     */
    public function __construct(string $key, mixed $value, array $tags = [])
    {
        $this->key = $key;
        $this->value = $value;
        $this->tags = $tags;
    }
}

