<?php

namespace SmartCache\Strategies;

use Illuminate\Contracts\Encryption\Encrypter;
use SmartCache\Contracts\OptimizationStrategy;

/**
 * Encryption Strategy for Sensitive Cached Data
 *
 * Encrypts cache values using Laravel's encryption service.
 * Useful for caching sensitive data like user tokens, API keys, etc.
 */
class EncryptionStrategy implements OptimizationStrategy
{
    protected Encrypter $encrypter;
    protected array $encryptedKeys = [];
    protected array $encryptedPatterns = [];
    protected bool $encryptAll = false;

    public function __construct(Encrypter $encrypter, array $config = [])
    {
        $this->encrypter = $encrypter;
        $this->encryptedKeys = $config['keys'] ?? [];
        $this->encryptedPatterns = $config['patterns'] ?? [];
        $this->encryptAll = $config['encrypt_all'] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, array $context = []): bool
    {
        if ($this->encryptAll) {
            return true;
        }

        $key = $context['key'] ?? '';

        if (\in_array($key, $this->encryptedKeys, true)) {
            return true;
        }

        foreach ($this->encryptedPatterns as $pattern) {
            if (@preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        $serialized = \serialize($value);
        $encrypted = $this->encrypter->encrypt($serialized);

        return [
            '_sc_encrypted' => true,
            'data' => $encrypted,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function restore(mixed $value, array $context = []): mixed
    {
        if (!\is_array($value) || !isset($value['_sc_encrypted']) || $value['_sc_encrypted'] !== true) {
            return $value;
        }

        try {
            $decrypted = $this->encrypter->decrypt($value['data']);
            return \unserialize($decrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'encryption';
    }

    /**
     * Add a key to the encrypted keys list.
     */
    public function addKey(string $key): static
    {
        if (!\in_array($key, $this->encryptedKeys, true)) {
            $this->encryptedKeys[] = $key;
        }
        return $this;
    }

    /**
     * Add a pattern to the encrypted patterns list.
     */
    public function addPattern(string $pattern): static
    {
        if (!\in_array($pattern, $this->encryptedPatterns, true)) {
            $this->encryptedPatterns[] = $pattern;
        }
        return $this;
    }

    /**
     * Enable encryption for all keys.
     */
    public function encryptAll(): static
    {
        $this->encryptAll = true;
        return $this;
    }

    /**
     * Disable encryption for all keys (use key/pattern matching).
     */
    public function encryptSelective(): static
    {
        $this->encryptAll = false;
        return $this;
    }
}

