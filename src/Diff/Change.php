<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Diff;

final readonly class Change
{
    public function __construct(
        public string $provider,
        public string $source,
        public ChangeType $type,
        public string $subject,
        public string $detail = '',
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'source' => $this->source,
            'type' => $this->type->value,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ];
    }
}
