<?php

declare(strict_types=1);

/**
 * The watch registry. Data-only by design: each provider lists its sources as
 * plain descriptors that SourceFactory turns into Source objects. Add a
 * provider by adding an entry; add a signal by adding a source.
 *
 * Source descriptor types:
 *   models-dev   — a provider's model roster from the shared models.dev catalog
 *                  (one keyless fetch serves every provider). `md_provider` is
 *                  the provider's id on models.dev. This is the primary roster
 *                  source — no API keys required.
 *   github-atom  — a public GitHub .atom feed (releases/commits/tags; no key).
 *                  Carries the request/response *schema* signal models.dev lacks.
 *   models       — (legacy) a provider's own /models endpoint (needs an API
 *                  key). Superseded by models-dev; kept available for providers
 *                  models.dev doesn't cover.
 *
 * `prism_hints` are the repos/prism paths a maintainer/agent reviews when this
 * provider drifts — they scope the follow-up, they are not auto-edited.
 */
return [
    'openai' => [
        'label' => 'OpenAI',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/OpenAI/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'OpenAI model roster (models.dev)', 'md_provider' => 'openai'],
            ['type' => 'github-atom', 'id' => 'openapi-spec', 'label' => 'openai/openai-openapi commits', 'url' => 'https://github.com/openai/openai-openapi/commits/main.atom'],
            ['type' => 'github-atom', 'id' => 'sdk-python', 'label' => 'openai/openai-python releases', 'url' => 'https://github.com/openai/openai-python/releases.atom'],
        ],
    ],

    'anthropic' => [
        'label' => 'Anthropic',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Anthropic/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Anthropic model roster (models.dev)', 'md_provider' => 'anthropic'],
            ['type' => 'github-atom', 'id' => 'sdk-python', 'label' => 'anthropics/anthropic-sdk-python releases', 'url' => 'https://github.com/anthropics/anthropic-sdk-python/releases.atom'],
        ],
    ],

    'gemini' => [
        'label' => 'Google Gemini',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Gemini/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Gemini model roster (models.dev)', 'md_provider' => 'google'],
            ['type' => 'github-atom', 'id' => 'sdk-python', 'label' => 'googleapis/python-genai releases', 'url' => 'https://github.com/googleapis/python-genai/releases.atom'],
        ],
    ],

    'mistral' => [
        'label' => 'Mistral',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Mistral/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Mistral model roster (models.dev)', 'md_provider' => 'mistral'],
        ],
    ],

    'groq' => [
        'label' => 'Groq',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Groq/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Groq model roster (models.dev)', 'md_provider' => 'groq'],
        ],
    ],

    'xai' => [
        'label' => 'xAI (Grok)',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/XAI/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'xAI model roster (models.dev)', 'md_provider' => 'xai'],
        ],
    ],

    'deepseek' => [
        'label' => 'DeepSeek',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/DeepSeek/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'DeepSeek model roster (models.dev)', 'md_provider' => 'deepseek'],
        ],
    ],

    'openrouter' => [
        'label' => 'OpenRouter',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/OpenRouter/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'OpenRouter model roster (models.dev)', 'md_provider' => 'openrouter'],
        ],
    ],

    'perplexity' => [
        'label' => 'Perplexity',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Perplexity/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Perplexity model roster (models.dev)', 'md_provider' => 'perplexity'],
        ],
    ],

    'qwen' => [
        'label' => 'Qwen (Alibaba)',
        'prism_hints' => ['src/Enums/Provider.php', 'src/Providers/Qwen/', 'config/prism.php'],
        'sources' => [
            ['type' => 'models-dev', 'id' => 'models-dev', 'label' => 'Qwen model roster (models.dev)', 'md_provider' => 'alibaba'],
        ],
    ],

    /*
     * Not an LLM provider — a protocol prism speaks. It is watched here because
     * this registry is a watch-target registry that happens to be named after
     * its first inhabitants, and MCP drift is the more dangerous kind: a new
     * model id is a string prism passes through untouched, while a protocol
     * revision breaks integrations outright.
     *
     * prism exposes tools through Laravel MCP (Tools/LaravelMcpTool bridging
     * Laravel\Mcp\Server\Tool) and suggests laravel/mcp in composer.json, so
     * both the spec and that package are live dependencies of ours.
     */
    'mcp' => [
        'label' => 'MCP (spec + laravel/mcp)',
        'prism_hints' => ['src/Tools/LaravelMcpTool.php', 'src/Tool.php', 'composer.json'],
        'sources' => [
            ['type' => 'github-atom', 'id' => 'spec', 'label' => 'modelcontextprotocol spec releases', 'url' => 'https://github.com/modelcontextprotocol/modelcontextprotocol/releases.atom'],
            ['type' => 'github-atom', 'id' => 'laravel-mcp', 'label' => 'laravel/mcp releases', 'url' => 'https://github.com/laravel/mcp/releases.atom'],
        ],
    ],
];
