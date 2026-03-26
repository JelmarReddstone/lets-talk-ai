<?php

namespace AgentReady;

/**
 * Extracts clean, readable content from HTML.
 * Strips visual clutter, navigation, ads, and scripts.
 * Returns structured markdown or plain text.
 */
class ContentExtractor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Extract clean content from an HTML string.
     * Returns structured array with title, content, metadata, etc.
     */
    public function extract(string $html, array $pageData = []): array
    {
        $doc = new \DOMDocument();
        $doc->encoding = 'UTF-8';

        // Suppress HTML5 parsing warnings
        $internalErrors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($doc);

        // Remove excluded elements first
        $this->removeExcludedElements($doc, $xpath);

        // Extract components
        $title = $this->extractTitle($doc, $xpath, $pageData);
        $description = $this->extractMetaDescription($doc, $xpath, $pageData);
        $mainContent = $this->extractMainContent($doc, $xpath);
        $metadata = $this->extractMetadata($doc, $xpath, $pageData);
        $images = $this->extractImages($doc, $xpath);
        $links = $this->extractLinks($doc, $xpath);
        $existingJsonLd = $this->extractExistingJsonLd($doc, $xpath);

        return [
            'title' => $title,
            'description' => $description,
            'content' => $mainContent,
            'metadata' => $metadata,
            'images' => $images,
            'links' => $links,
            'existing_structured_data' => $existingJsonLd,
        ];
    }

    /**
     * Convert extracted content to clean markdown.
     */
    public function toMarkdown(array $extracted, array $frontmatter = []): string
    {
        $output = "---\n";

        // Build frontmatter
        $fm = array_merge([
            'title' => $extracted['title'],
            'description' => $extracted['description'],
        ], $frontmatter);

        if (!empty($extracted['metadata'])) {
            $fm['metadata'] = $extracted['metadata'];
        }

        foreach ($fm as $key => $value) {
            $output .= $this->yamlEncode($key, $value, 0);
        }

        $output .= "---\n\n";

        // Title
        if (!empty($extracted['title'])) {
            $output .= "# " . $extracted['title'] . "\n\n";
        }

        // Description
        if (!empty($extracted['description'])) {
            $output .= "> " . $extracted['description'] . "\n\n";
        }

        // Main content
        if (!empty($extracted['content'])) {
            $output .= $extracted['content'] . "\n\n";
        }

        // Key images
        if (!empty($extracted['images'])) {
            $output .= "## Images\n\n";
            foreach ($extracted['images'] as $img) {
                $alt = $img['alt'] ?: 'Image';
                $output .= "- ![{$alt}]({$img['src']})\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Convert extracted content to structured JSON.
     */
    public function toJson(array $extracted, array $extra = []): array
    {
        return array_merge([
            '@context' => 'https://agentready.dev/schema',
            '@type' => 'AgentReadyPage',
            'title' => $extracted['title'],
            'description' => $extracted['description'],
            'content' => $extracted['content'],
            'metadata' => $extracted['metadata'],
            'images' => $extracted['images'],
            'links' => $extracted['links'],
            'structured_data' => $extracted['existing_structured_data'],
        ], $extra);
    }

    private function removeExcludedElements(\DOMDocument $doc, \DOMXPath $xpath): void
    {
        $excludeSelector = $this->config->get('content_selectors.exclude', '');
        $tags = array_map('trim', explode(',', $excludeSelector));

        foreach ($tags as $tag) {
            if (empty($tag)) continue;

            // Handle tag names (nav, script, style, etc.)
            if (preg_match('/^[a-z]+$/i', $tag)) {
                $nodes = $xpath->query("//{$tag}");
            }
            // Handle class selectors (.classname)
            elseif (strpos($tag, '.') === 0) {
                $class = substr($tag, 1);
                $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
            }
            // Handle ID selectors (#id)
            elseif (strpos($tag, '#') === 0) {
                $id = substr($tag, 1);
                $nodes = $xpath->query("//*[@id='{$id}']");
            } else {
                continue;
            }

            if ($nodes === false) continue;

            foreach ($nodes as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private function extractTitle(\DOMDocument $doc, \DOMXPath $xpath, array $pageData): string
    {
        // Use explicit page data first
        if (!empty($pageData['title'])) {
            return $pageData['title'];
        }

        $selectors = $this->config->get('content_selectors.title', 'h1');
        $tags = array_map('trim', explode(',', $selectors));

        foreach ($tags as $tag) {
            $nodes = null;
            if (preg_match('/^[a-z0-9]+$/i', $tag)) {
                $nodes = $xpath->query("//{$tag}");
            } elseif (strpos($tag, '.') === 0) {
                $class = substr($tag, 1);
                $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
            }

            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        // Fallback to <title> tag
        $titleNodes = $doc->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }

        return '';
    }

    private function extractMetaDescription(\DOMDocument $doc, \DOMXPath $xpath, array $pageData): string
    {
        if (!empty($pageData['description'])) {
            return $pageData['description'];
        }

        // Try meta description
        $nodes = $xpath->query("//meta[@name='description']/@content");
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        // Try OpenGraph description
        $nodes = $xpath->query("//meta[@property='og:description']/@content");
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        return '';
    }

    private function extractMainContent(\DOMDocument $doc, \DOMXPath $xpath): string
    {
        $selectors = $this->config->get('content_selectors.main_content', 'main, article');
        $tags = array_map('trim', explode(',', $selectors));

        $contentNode = null;

        foreach ($tags as $tag) {
            $nodes = null;
            if (preg_match('/^[a-z0-9]+$/i', $tag)) {
                $nodes = $xpath->query("//{$tag}");
            } elseif (strpos($tag, '.') === 0) {
                $class = substr($tag, 1);
                $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
            } elseif (strpos($tag, '#') === 0) {
                $id = substr($tag, 1);
                $nodes = $xpath->query("//*[@id='{$id}']");
            }

            if ($nodes && $nodes->length > 0) {
                $contentNode = $nodes->item(0);
                break;
            }
        }

        // Fallback to body
        if (!$contentNode) {
            $bodies = $doc->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $contentNode = $bodies->item(0);
            }
        }

        if (!$contentNode) {
            return '';
        }

        return $this->nodeToMarkdown($contentNode);
    }

    private function extractMetadata(\DOMDocument $doc, \DOMXPath $xpath, array $pageData): array
    {
        $metadata = $pageData['metadata'] ?? [];

        // Extract from metadata selectors
        $metaSelector = $this->config->get('content_selectors.metadata', '');
        if (!empty($metaSelector)) {
            $tags = array_map('trim', explode(',', $metaSelector));
            foreach ($tags as $tag) {
                $nodes = null;
                if (strpos($tag, '.') === 0) {
                    $class = substr($tag, 1);
                    $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
                }

                if ($nodes) {
                    foreach ($nodes as $node) {
                        $label = trim($node->getAttribute('data-label') ?: $node->getAttribute('class'));
                        $value = trim($node->textContent);
                        if ($label && $value) {
                            $metadata[$label] = $value;
                        }
                    }
                }
            }
        }

        // Extract common meta tags
        $metaNames = ['author', 'keywords', 'robots', 'language', 'geo.region', 'geo.placename'];
        foreach ($metaNames as $name) {
            $nodes = $xpath->query("//meta[@name='{$name}']/@content");
            if ($nodes && $nodes->length > 0) {
                $metadata[$name] = trim($nodes->item(0)->nodeValue);
            }
        }

        // Extract OG data
        $ogProps = ['og:type', 'og:locale', 'og:site_name', 'og:updated_time'];
        foreach ($ogProps as $prop) {
            $nodes = $xpath->query("//meta[@property='{$prop}']/@content");
            if ($nodes && $nodes->length > 0) {
                $key = str_replace('og:', 'og_', $prop);
                $metadata[$key] = trim($nodes->item(0)->nodeValue);
            }
        }

        return $metadata;
    }

    private function extractImages(\DOMDocument $doc, \DOMXPath $xpath): array
    {
        $images = [];
        $nodes = $xpath->query("//img[@src]");

        if ($nodes) {
            foreach ($nodes as $node) {
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');

                // Skip tiny images (tracking pixels, icons)
                $width = $node->getAttribute('width');
                $height = $node->getAttribute('height');
                if (($width && intval($width) < 50) || ($height && intval($height) < 50)) {
                    continue;
                }

                // Skip data URIs and SVGs
                if (strpos($src, 'data:') === 0 || strpos($src, '.svg') !== false) {
                    continue;
                }

                $images[] = [
                    'src' => $src,
                    'alt' => $alt ?: '',
                    'title' => $node->getAttribute('title') ?: '',
                ];
            }
        }

        return $images;
    }

    private function extractLinks(\DOMDocument $doc, \DOMXPath $xpath): array
    {
        $links = [];
        $nodes = $xpath->query("//a[@href]");

        if ($nodes) {
            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');
                $text = trim($node->textContent);

                // Skip anchors, javascript, empty
                if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
                    continue;
                }

                if (!empty($text)) {
                    $links[] = [
                        'href' => $href,
                        'text' => $text,
                        'rel' => $node->getAttribute('rel') ?: '',
                    ];
                }
            }
        }

        return $links;
    }

    private function extractExistingJsonLd(\DOMDocument $doc, \DOMXPath $xpath): array
    {
        $results = [];
        $scripts = $xpath->query("//script[@type='application/ld+json']");

        if ($scripts) {
            foreach ($scripts as $script) {
                $json = json_decode(trim($script->textContent), true);
                if ($json) {
                    $results[] = $json;
                }
            }
        }

        return $results;
    }

    /**
     * Convert a DOM node tree to clean markdown.
     */
    private function nodeToMarkdown(\DOMNode $node, int $depth = 0): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->textContent;
                // Collapse whitespace
                $text = preg_replace('/\s+/', ' ', $text);
                $output .= $text;
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            switch ($tag) {
                case 'h1':
                    $output .= "\n## " . trim($child->textContent) . "\n\n";
                    break;

                case 'h2':
                    $output .= "\n### " . trim($child->textContent) . "\n\n";
                    break;

                case 'h3':
                    $output .= "\n#### " . trim($child->textContent) . "\n\n";
                    break;

                case 'h4':
                case 'h5':
                case 'h6':
                    $output .= "\n**" . trim($child->textContent) . "**\n\n";
                    break;

                case 'p':
                    $inner = $this->nodeToMarkdown($child, $depth);
                    $trimmed = trim($inner);
                    if (!empty($trimmed)) {
                        $output .= $trimmed . "\n\n";
                    }
                    break;

                case 'br':
                    $output .= "\n";
                    break;

                case 'strong':
                case 'b':
                    $output .= "**" . trim($child->textContent) . "**";
                    break;

                case 'em':
                case 'i':
                    $output .= "*" . trim($child->textContent) . "*";
                    break;

                case 'a':
                    $href = $child->getAttribute('href');
                    $text = trim($child->textContent);
                    if (!empty($href) && !empty($text)) {
                        $output .= "[{$text}]({$href})";
                    } else {
                        $output .= $text;
                    }
                    break;

                case 'ul':
                    $output .= "\n" . $this->listToMarkdown($child, '-', $depth) . "\n";
                    break;

                case 'ol':
                    $output .= "\n" . $this->listToMarkdown($child, '1.', $depth) . "\n";
                    break;

                case 'li':
                    $output .= $this->nodeToMarkdown($child, $depth);
                    break;

                case 'table':
                    $output .= "\n" . $this->tableToMarkdown($child) . "\n";
                    break;

                case 'img':
                    $src = $child->getAttribute('src');
                    $alt = $child->getAttribute('alt') ?: 'Image';
                    if ($src) {
                        $output .= "![{$alt}]({$src})";
                    }
                    break;

                case 'blockquote':
                    $inner = trim($this->nodeToMarkdown($child, $depth));
                    $lines = explode("\n", $inner);
                    $quoted = implode("\n", array_map(fn($l) => "> " . $l, $lines));
                    $output .= "\n" . $quoted . "\n\n";
                    break;

                case 'code':
                    $output .= "`" . trim($child->textContent) . "`";
                    break;

                case 'pre':
                    $output .= "\n```\n" . trim($child->textContent) . "\n```\n\n";
                    break;

                case 'div':
                case 'section':
                case 'article':
                case 'span':
                case 'figure':
                case 'figcaption':
                case 'main':
                case 'aside':
                default:
                    // Recurse into container elements
                    $output .= $this->nodeToMarkdown($child, $depth);
                    break;
            }
        }

        return $output;
    }

    private function listToMarkdown(\DOMNode $node, string $marker, int $depth): string
    {
        $output = '';
        $indent = str_repeat('  ', $depth);
        $counter = 1;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $prefix = $marker === '1.' ? "{$counter}." : $marker;
            $content = trim($this->nodeToMarkdown($child, $depth + 1));
            $output .= "{$indent}{$prefix} {$content}\n";
            $counter++;
        }

        return $output;
    }

    private function tableToMarkdown(\DOMNode $table): string
    {
        $rows = [];
        $headerRow = null;

        foreach ($table->childNodes as $child) {
            $tag = strtolower($child->nodeName ?? '');

            if ($tag === 'thead') {
                foreach ($child->childNodes as $tr) {
                    if (strtolower($tr->nodeName ?? '') === 'tr') {
                        $headerRow = $this->extractTableRow($tr, 'th');
                    }
                }
            } elseif ($tag === 'tbody') {
                foreach ($child->childNodes as $tr) {
                    if (strtolower($tr->nodeName ?? '') === 'tr') {
                        $rows[] = $this->extractTableRow($tr, 'td');
                    }
                }
            } elseif ($tag === 'tr') {
                $row = $this->extractTableRow($child, 'td');
                if (!$headerRow) {
                    $headerRow = $row;
                } else {
                    $rows[] = $row;
                }
            }
        }

        if (!$headerRow && !empty($rows)) {
            $headerRow = $rows[0];
            array_shift($rows);
        }

        if (!$headerRow) return '';

        $output = '| ' . implode(' | ', $headerRow) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, count($headerRow), '---')) . " |\n";

        foreach ($rows as $row) {
            // Pad row to header length
            while (count($row) < count($headerRow)) $row[] = '';
            $output .= '| ' . implode(' | ', $row) . " |\n";
        }

        return $output;
    }

    private function extractTableRow(\DOMNode $tr, string $cellTag): array
    {
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            $tag = strtolower($cell->nodeName ?? '');
            if ($tag === 'td' || $tag === 'th') {
                $cells[] = trim($cell->textContent);
            }
        }
        return $cells;
    }

    private function yamlEncode(string $key, $value, int $indent): string
    {
        $prefix = str_repeat('  ', $indent);

        if (is_array($value)) {
            if ($this->isAssociativeArray($value)) {
                $output = "{$prefix}{$key}:\n";
                foreach ($value as $k => $v) {
                    $output .= $this->yamlEncode($k, $v, $indent + 1);
                }
                return $output;
            } else {
                $output = "{$prefix}{$key}:\n";
                foreach ($value as $v) {
                    if (is_scalar($v)) {
                        $output .= "{$prefix}  - " . $this->yamlScalar($v) . "\n";
                    }
                }
                return $output;
            }
        }

        return "{$prefix}{$key}: " . $this->yamlScalar($value) . "\n";
    }

    private function yamlScalar($value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_numeric($value)) return (string)$value;
        if (empty($value)) return '""';

        // Quote strings that contain special YAML characters
        if (preg_match('/[:#\[\]{}|>!@%&*?,]/', (string)$value) || str_contains((string)$value, "\n")) {
            return '"' . addslashes((string)$value) . '"';
        }

        return (string)$value;
    }

    private function isAssociativeArray(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
