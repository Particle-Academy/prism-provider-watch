<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Support;

/**
 * Dependency-free .env loader — just enough to pick up provider keys and dir
 * overrides for local runs. Never overrides a variable already present in the
 * real environment (CI/Genie-cron-provided values win).
 */
final class DotEnv
{
    public static function load(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}
