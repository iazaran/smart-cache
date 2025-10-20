<?php

namespace SmartCache\Events;

class KeyWritten
{
    /**
     * The key that was written.
     *
     * @var string
     */
    public string $key;

    /**
     * The value that was written.
     *
     * @var mixed
     */
    public mixed $value;

    /**
     * The number of seconds the key should be valid.
     *
     * @var int|null
     */
    public ?int $seconds;

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
     * @param int|null $seconds
     * @param array $tags
     */
    public function __construct(string $key, mixed $value, ?int $seconds = null, array $tags = [])
    {
        $this->key = $key;
        $this->value = $value;
        $this->seconds = $seconds;
        $this->tags = $tags;
    }
}

