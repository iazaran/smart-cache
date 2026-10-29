<?php

namespace SmartCache\Strategies;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\Strategies\Concerns\RestrictsUnserialization;

/**
 * Encryption Strategy for Sensitive Cached Data
 *
 * Encrypts cache values using Laravel's encryption service.
 * Useful for caching sensitive data like user tokens, API keys, etc.
 */
class EncryptionStrategy implements OptimizationStrategy
{
    use RestrictsUnserialization;

    protected Encrypter $encrypter;
    protected array $encryptedKeys = [];
    protected array $encryptedPatterns = [];
    protected bool $encryptAll = false;

    /**
     * Patterns already reported as invalid, so each is logged once.
     *
     * @var array<string, true>
     */
    private array $reportedInvalidPatterns = [];

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

        foreach ($this->candidateKeys($context) as $key) {
            if (\in_array($key, $this->encryptedKeys, true)) {
                return true;
            }

            foreach ($this->encryptedPatterns as $pattern) {
                $matched = @preg_match($pattern, $key);

                if ($matched === 1) {
                    return true;
                }

                if ($matched === false) {
                    $this->reportInvalidPattern((string) $pattern);
                }
            }
        }

        return false;
    }

    /**
     * Log a pattern that is not a valid regular expression. It matches nothing,
     * so the values it was meant to protect are stored unencrypted; that used
     * to happen without a trace. A glob such as 'user_token_*' is a common
     * cause: patterns are PCRE regexes such as '/^user_token_/'.
     *
     * @param string $pattern
     * @return void
     */
    private function reportInvalidPattern(string $pattern): void
    {
        if (isset($this->reportedInvalidPatterns[$pattern])) {
            return;
        }

        $this->reportedInvalidPatterns[$pattern] = true;

        try {
            Log::warning("SmartCache encryption pattern [{$pattern}] is not a valid regular expression, so it matches no keys and their values are not encrypted. Use a PCRE pattern such as '/^user_token_/'.");
        } catch (\Throwable $e) {
            // No logger outside a Laravel application.
        }
    }

    /**
     * Keys to match against the configured keys and patterns.
     *
     * The context key carries the active namespace ("tenant:user_token_1"), while
     * configured keys and patterns are written without it. Matching both forms
     * keeps namespaced writes encrypted.
     *
     * @param array $context
     * @return array<int, string>
     */
    protected function candidateKeys(array $context): array
    {
        $key = (string) ($context['key'] ?? '');
        $namespace = $context['namespace'] ?? null;

        if (\is_string($namespace) && $namespace !== '' && \str_starts_with($key, $namespace . ':')) {
            return [$key, \substr($key, \strlen($namespace) + 1)];
        }

        return [$key];
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

        // Throwing (rather than returning null) lets SmartCache treat the entry as
        // corrupted: self-healing evicts it and remember() regenerates it, instead
        // of serving null as a cache hit — e.g. after an APP_KEY rotation.
        try {
            // With a class restriction the encrypter's own unserialize() is
            // skipped too: its payload is only the serialized value as a string.
            $decrypted = $this->restrictsUnserialization()
                ? \unserialize($this->encrypter->decrypt($value['data'], false), ['allowed_classes' => false])
                : $this->encrypter->decrypt($value['data']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SmartCache encrypted payload could not be decrypted: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_string($decrypted)) {
            throw new \RuntimeException('SmartCache encrypted payload is not a serialized value.');
        }

        return $this->unserializePayload($decrypted);
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

