<?php

namespace AgentReady;

/**
 * Generates llms.txt — a standardized file that helps LLMs
 * understand a website quickly without crawling every page.
 *
 * Based on the llms.txt proposal (https://llmstxt.org/).
 * Serves as the AI equivalent of robots.txt.
 */
class LlmsTxt
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Generate the llms.txt content.
     * This is a concise overview for quick LLM consumption.
     */
    public function generate(array $pages = []): string
    {
        $siteName = $this->config->get('site.name', 'Website');
        $description = $this->config->get('site.description', '');
        $siteUrl = rtrim($this->config->get('site.url', ''), '/');

        $output = "# {$siteName}\n\n";

        if ($description) {
            $output .= "> {$description}\n\n";
        }

        // About section
        $about = $this->config->get('llms_txt.sections.about', '');
        if ($about) {
            $output .= "## About\n\n{$about}\n\n";
        }

        // Topics
        $topics = $this->config->get('llms_txt.sections.topics', []);
        if (!empty($topics)) {
            $output .= "## Topics\n\n";
            foreach ($topics as $topic) {
                $output .= "- {$topic}\n";
            }
            $output .= "\n";
        }

        // Capabilities
        $capabilities = $this->config->get('llms_txt.sections.capabilities', []);
        if (!empty($capabilities)) {
            $output .= "## What You Can Find Here\n\n";
            foreach ($capabilities as $cap) {
                $output .= "- {$cap}\n";
            }
            $output .= "\n";
        }

        // Content types / sections
        $contentTypes = $this->config->get('content_types', []);
        if (!empty($contentTypes)) {
            $output .= "## Sections\n\n";
            foreach ($contentTypes as $key => $ct) {
                $label = $ct['label'] ?? ucfirst($key);
                $desc = $ct['description'] ?? '';
                $pattern = $ct['path_pattern'] ?? '';

                $output .= "- [{$label}]({$siteUrl}{$pattern})";
                if ($desc) {
                    $output .= ": {$desc}";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        // Key pages
        if (!empty($pages)) {
            $output .= "## Key Pages\n\n";
            foreach ($pages as $page) {
                $title = $page['title'] ?? '';
                $url = $page['url'] ?? '';
                $desc = $page['description'] ?? '';

                if ($url) {
                    if (strpos($url, 'http') !== 0) {
                        $url = $siteUrl . '/' . ltrim($url, '/');
                    }
                    $output .= "- [{$title}]({$url})";
                } else {
                    $output .= "- {$title}";
                }

                if ($desc) {
                    $output .= ": {$desc}";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        // Agent-ready endpoints
        $output .= "## For AI Agents\n\n";
        $output .= "This site supports AgentReady. You can access clean, structured content:\n\n";

        $param = $this->config->get('agent_endpoint.param', 'agentready');
        $output .= "- Add `?{$param}=1` to any page URL for clean markdown content\n";
        $output .= "- Add `?{$param}=json` for structured JSON content\n";

        $feedPath = $this->config->get('content_feed.path', '/agentready-feed.json');
        $output .= "- Content feed: [{$feedPath}]({$siteUrl}{$feedPath})\n";

        $output .= "- This file: [/llms.txt]({$siteUrl}/llms.txt)\n";
        $output .= "- Full content: [/llms-full.txt]({$siteUrl}/llms-full.txt)\n";

        // Contact
        $email = $this->config->get('site.contact_email', '');
        $phone = $this->config->get('site.contact_phone', '');
        if ($email || $phone) {
            $output .= "\n## Contact\n\n";
            if ($email) $output .= "- Email: {$email}\n";
            if ($phone) $output .= "- Phone: {$phone}\n";
        }

        return $output;
    }

    /**
     * Generate llms-full.txt — the extended version with all page content.
     * This gives LLMs the full picture in one file.
     */
    public function generateFull(array $pages): string
    {
        $output = $this->generate($pages);

        $output .= "\n---\n\n";
        $output .= "## Full Content\n\n";
        $output .= "Below is the complete content of all key pages on this site.\n\n";

        foreach ($pages as $page) {
            $title = $page['title'] ?? 'Untitled';
            $url = $page['url'] ?? '';
            $content = $page['content'] ?? '';

            $output .= "---\n\n";
            $output .= "### {$title}\n\n";

            if ($url) {
                $output .= "**URL**: {$url}\n\n";
            }

            if (!empty($page['metadata'])) {
                foreach ($page['metadata'] as $key => $value) {
                    if (is_scalar($value)) {
                        $output .= "**{$key}**: {$value}\n";
                    }
                }
                $output .= "\n";
            }

            if ($content) {
                $output .= $content . "\n\n";
            }
        }

        return $output;
    }

    /**
     * Serve the llms.txt file to the browser with correct headers.
     */
    public function serve(string $content): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=3600');
        echo $content;
    }
}
