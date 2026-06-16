<?php

namespace SmartCache\Events;

class TagFlushed
{
    /**
     * The tag that was flushed.
     *
     * @var string
     */
    public string $tag;

    /**
     * Number of live keys associated with the tag when it was flushed.
     *
     * @var int
     */
    public int $keyCount;

    /**
     * Source of the flush operation, such as manual, model, or model_helper.
     *
     * @var string
     */
    public string $source;

    /**
     * Create a new event instance.
     *
     * @param string $tag
     * @param int $keyCount
     * @param string $source
     */
    public function __construct(string $tag, int $keyCount, string $source = 'manual')
    {
        $this->tag = $tag;
        $this->keyCount = $keyCount;
        $this->source = $source;
    }
}
