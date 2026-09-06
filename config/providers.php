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
 *   github-issues — open issues or pull requests on a repo, via the REST API.
 *                  GitHub has no Atom for these (issues.atom/pulls.atom answer
 *                  406). `repo` is owner/name, `kind` is issues|pulls.
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
     *
     * THE OFFICIAL SDKs ARE WATCHED TOO, and for a reason that does not apply to
     * any other target in this file. Every other family here treats the PHP
     * package as the reference and the ports as things that must agree with it.
     * MCP cannot work that way: it is somebody else's protocol, so the SPEC and
     * the reference SDKs are the source of truth, and all three of our packages
     * are ports of THAT. A change in the TypeScript SDK is not a competitor
     * shipping something — it is the definition moving underneath us.
     *
     * Watched as the definition rather than as a dependency: we vendor neither
     * SDK (prism-mcp-ts has zero dependencies and implements the wire format
     * itself). They are read for what the protocol now says, and for how far
     * the deployed ecosystem has actually moved — which is the gap that
     * matters. As of 2026-09-06 the spec's latest release is 2026-07-28, which
     * our client speaks, while servers in the wild are still on 2024-11-05
     * through 2025-06-18. We are ahead of the deployment, not behind the spec,
     * and those are opposite problems with opposite fixes.
     */
    'mcp' => [
        'label' => 'MCP (spec + official SDKs + laravel/mcp)',
        'prism_hints' => [
            'repos/prism-mcp/src/',
            'repos/prism-mcp-ts/src/index.ts',
            'repos/prism-mcp-py/src/prism_mcp/__init__.py',
            'src/Tools/LaravelMcpTool.php',
            'composer.json',
        ],
        'sources' => [
            ['type' => 'github-atom', 'id' => 'spec', 'label' => 'modelcontextprotocol spec releases', 'url' => 'https://github.com/modelcontextprotocol/modelcontextprotocol/releases.atom'],
            ['type' => 'github-atom', 'id' => 'sdk-typescript', 'label' => 'MCP TypeScript SDK releases', 'url' => 'https://github.com/modelcontextprotocol/typescript-sdk/releases.atom'],
            ['type' => 'github-atom', 'id' => 'sdk-python', 'label' => 'MCP Python SDK releases', 'url' => 'https://github.com/modelcontextprotocol/python-sdk/releases.atom'],
            ['type' => 'github-atom', 'id' => 'laravel-mcp', 'label' => 'laravel/mcp releases', 'url' => 'https://github.com/laravel/mcp/releases.atom'],
        ],
    ],

    /*
     * The fork's own upstream. Not a provider and not a protocol — the source
     * of the community work this fork exists to absorb, and the one signal the
     * watcher was blind to while everything it carried came from upstream in
     * the first place.
     *
     * GitHub publishes Atom for commits, releases and tags but not for issues
     * or pulls (both answer 406), so these read the REST API. A token lifts the
     * rate limit from 60/hour to 5000 and is used when GITHUB_TOKEN is set.
     */
    'upstream' => [
        'label' => 'Upstream prism-php/prism',
        'prism_hints' => ['src/', 'tests/', 'docs/'],
        'sources' => [
            ['type' => 'github-issues', 'id' => 'issues', 'label' => 'prism-php/prism open issues', 'repo' => 'prism-php/prism', 'kind' => 'issues'],
            ['type' => 'github-issues', 'id' => 'pulls', 'label' => 'prism-php/prism open pull requests', 'repo' => 'prism-php/prism', 'kind' => 'pulls'],
        ],
    ],
];
