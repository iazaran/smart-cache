<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;

/**
 * Decodes entries of a strategy that is switched off, and never encodes.
 *
 * Turning compression, chunking, encryption, or serialization off used to make
 * the entries it had already written come back as internal wrappers such as
 * ['_sc_compressed' => true, ...] until they expired, which for
 * rememberForever() entries was never. The service provider registers one of
 * these for every disabled strategy so those entries still restore.
 *
 * The real strategy is built on first use, and only for a value that carries
 * its marker, so a disabled encryption strategy never resolves the encrypter
 * (and needs no APP_KEY) unless an encrypted entry is actually read.
 */
class RestoreOnlyStrategy implements OptimizationStrategy
{
    /**
     * @var string
     */
    protected string $identifier;

    /**
     * @var string
     */
    protected string $marker;

    /**
     * @var \Closure(): OptimizationStrategy
     */
    protected \Closure $factory;

    /**
     * @var OptimizationStrategy|null
     */
    protected ?OptimizationStrategy $strategy = null;

    /**
     * @param string $identifier Identifier of the wrapped strategy
     * @param string $marker Top-level key the strategy sets to true on the values it writes
     * @param \Closure(): OptimizationStrategy $factory Builds the strategy that decodes
     */
    public function __construct(string $identifier, string $marker, \Closure $factory)
    {
        $this->identifier = $identifier;
        $this->marker = $marker;
        $this->factory = $factory;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, array $context = []): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(mixed $value, array $context = []): mixed
    {
        if (!\is_array($value) || ($value[$this->marker] ?? null) !== true) {
            return $value;
        }

        if ($this->strategy === null) {
            $this->strategy = ($this->factory)();
        }

        return $this->strategy->restore($value, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
