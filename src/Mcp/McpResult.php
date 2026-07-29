<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Mcp;

final readonly class McpResult
{
    public function __construct(
        public bool $ok,
        public string $text = '',
        public string $error = '',
    ) {}

    public static function ok(string $text = ''): self
    {
        return new self(true, $text);
    }

    public static function failed(string $error): self
    {
        return new self(false, '', $error);
    }
}
