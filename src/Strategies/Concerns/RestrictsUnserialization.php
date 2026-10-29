<?php

namespace SmartCache\Strategies\Concerns;

/**
 * Applies smart-cache.serializable_classes to the unserialize() calls a
 * strategy makes itself.
 *
 * Laravel's cache.serializable_classes is applied by the store when it reads
 * a value, but the payloads SmartCache compresses, encrypts, or serializes are
 * strings to the store and are unserialized here. Without a restriction, a
 * large object came back intact where a small one became
 * __PHP_Incomplete_Class. The restriction is opt-in; null keeps the historical
 * unrestricted behavior.
 */
trait RestrictsUnserialization
{
    /**
     * @var array|bool|null Classes allowed when unserializing; null for no restriction
     */
    private array|bool|null $serializableClasses = null;

    /**
     * Restrict the classes this strategy unserializes, as Laravel's
     * cache.serializable_classes does: false allows none, an array allows
     * those classes, and true or null allows all.
     *
     * @param array|bool|null $classes
     * @return static
     */
    public function setSerializableClasses(array|bool|null $classes): static
    {
        $this->serializableClasses = $classes;

        return $this;
    }

    /**
     * @param string $data
     * @return mixed
     */
    protected function unserializePayload(string $data): mixed
    {
        if ($this->serializableClasses === null) {
            return \unserialize($data);
        }

        return \unserialize($data, ['allowed_classes' => $this->serializableClasses]);
    }

    /**
     * Whether unserialize() calls are restricted.
     *
     * @return bool
     */
    protected function restrictsUnserialization(): bool
    {
        return $this->serializableClasses !== null;
    }
}
