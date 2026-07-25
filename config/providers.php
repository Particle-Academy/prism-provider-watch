<?php

declare(strict_types=1);

use Prism\ProviderWatch\Sources\ModelsEndpointSource;

/**
 * The watch registry. Data-only by design: each provider lists its sources as
 * plain descriptors that SourceFactory turns into Source objects. Add a
 * provider by adding an entry; add a signal by adding a source.
 *
 * Source descriptor types:
 *   models       — a "list models" endpoint (needs an API key; skipped without one)
 *   github-atom  — a public GitHub .atom feed (releases/commits/tags; no key)
 *
 * `prism_hints` are the repos/prism paths a maintainer/agent reviews when this
 * provider drifts — they scope the follow-up, they are not auto-edited.
 */
return [
    'openai' => [
        'label' => 'OpenAI',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/OpenAI/', 'config/prism.php'],
        'sources' => [
            [
                'type' => 'models',
                'id' => 'models',
                'label' => 'OpenAI model roster',
                'url' => 'https://api.openai.com/v1/models',
                'auth' => ModelsEndpointSource::AUTH_BEARER,
                'shape' => ModelsEndpointSource::SHAPE_OPENAI,
                'key_env' => ['OPENAI_API_KEY'],
            ],
            [
                'type' => 'github-atom',
                'id' => 'openapi-spec',
                'label' => 'openai/openai-openapi commits',
                'url' => 'https://github.com/openai/openai-openapi/commits/main.atom',
            ],
            [
                'type' => 'github-atom',
                'id' => 'sdk-python',
                'label' => 'openai/openai-python releases',
                'url' => 'https://github.com/openai/openai-python/releases.atom',
            ],
        ],
    ],

    'anthropic' => [
        'label' => 'Anthropic',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Anthropic/', 'config/prism.php'],
        'sources' => [
            [
                'type' => 'models',
                'id' => 'models',
                'label' => 'Anthropic model roster',
                'url' => 'https://api.anthropic.com/v1/models',
                'auth' => ModelsEndpointSource::AUTH_X_API_KEY,
                'shape' => ModelsEndpointSource::SHAPE_OPENAI,
                'headers' => ['anthropic-version' => '2023-06-01'],
                'key_env' => ['ANTHROPIC_API_KEY'],
            ],
            [
                'type' => 'github-atom',
                'id' => 'sdk-python',
                'label' => 'anthropics/anthropic-sdk-python releases',
                'url' => 'https://github.com/anthropics/anthropic-sdk-python/releases.atom',
            ],
        ],
    ],

    'gemini' => [
        'label' => 'Google Gemini',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Gemini/', 'config/prism.php'],
        'sources' => [
            [
                'type' => 'models',
                'id' => 'models',
                'label' => 'Gemini model roster',
                'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                'auth' => ModelsEndpointSource::AUTH_QUERY,
                'shape' => ModelsEndpointSource::SHAPE_GOOGLE,
                'key_env' => ['GEMINI_API_KEY', 'GOOGLE_API_KEY'],
            ],
            [
                'type' => 'github-atom',
                'id' => 'sdk-python',
                'label' => 'googleapis/python-genai releases',
                'url' => 'https://github.com/googleapis/python-genai/releases.atom',
            ],
        ],
    ],
];
