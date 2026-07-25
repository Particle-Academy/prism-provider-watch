<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Http;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
