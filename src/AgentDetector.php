<?php

namespace AgentReady;

/**
 * Detects AI crawlers, bots, and agents from request headers.
 */
class AgentDetector
{
    /**
     * Known AI crawler user-agent fragments.
     * This list is updated as new AI crawlers emerge.
     */
    private const KNOWN_AGENTS = [
        'GPTBot'            => 'openai',
        'ChatGPT-User'      => 'openai',
        'OAI-SearchBot'     => 'openai',
        'Google-Extended'    => 'google',
        'Googlebot'         => 'google',
        'Google-CloudVertexBot' => 'google',
        'Claude-Web'        => 'anthropic',
        'ClaudeBot'         => 'anthropic',
        'Applebot-Extended' => 'apple',
        'Applebot'          => 'apple',
        'PerplexityBot'     => 'perplexity',
        'Bytespider'        => 'bytedance',
        'CCBot'             => 'commoncrawl',
        'cohere-ai'         => 'cohere',
        'Diffbot'           => 'diffbot',
        'FacebookBot'       => 'meta',
        'Meta-ExternalAgent' => 'meta',
        'Amazonbot'         => 'amazon',
        'YouBot'            => 'you.com',
        'AI2Bot'            => 'ai2',
        'Scrapy'            => 'generic',
        'PetalBot'          => 'huawei',
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Check if the current request comes from a known AI agent.
     */
    public function isAgent(?string $userAgent = null, ?string $acceptHeader = null): bool
    {
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $acceptHeader = $acceptHeader ?? ($_SERVER['HTTP_ACCEPT'] ?? '');

        // Check user-agent
        if ($this->matchesKnownAgent($userAgent)) {
            return true;
        }

        // Check if requesting agent-friendly format via Accept header
        $agentHeaders = $this->config->get('agent_endpoint.accept_headers', []);
        foreach ($agentHeaders as $header) {
            if (stripos($acceptHeader, $header) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the request explicitly asks for agent-ready content
     * via query parameter.
     */
    public function isAgentRequest(): bool
    {
        $param = $this->config->get('agent_endpoint.param', 'agentready');
        return isset($_GET[$param]);
    }

    /**
     * Identify which AI agent is making the request.
     * Returns ['name' => string, 'vendor' => string] or null.
     */
    public function identify(?string $userAgent = null): ?array
    {
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

        foreach (self::KNOWN_AGENTS as $fragment => $vendor) {
            if (stripos($userAgent, $fragment) !== false) {
                return [
                    'name' => $fragment,
                    'vendor' => $vendor,
                ];
            }
        }

        return null;
    }

    /**
     * Get the requested output format.
     */
    public function getRequestedFormat(): string
    {
        $param = $this->config->get('agent_endpoint.param', 'agentready');

        // Explicit format parameter
        if (isset($_GET[$param])) {
            $format = $_GET[$param];
            if (in_array($format, ['json', 'markdown', 'md', '1', ''], true)) {
                return $format === 'json' ? 'json' : 'markdown';
            }
        }

        // Accept header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false || stripos($accept, 'application/agent+json') !== false) {
            return 'json';
        }

        return 'markdown';
    }

    private function matchesKnownAgent(string $userAgent): bool
    {
        foreach (self::KNOWN_AGENTS as $fragment => $vendor) {
            if (stripos($userAgent, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }
}
