<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/** Renders Markdown source into plain text or sanitized HTML. */
final class MarkdownRenderer
{
    /**
     * Whitelist of allowed HTML tags -> allowed attributes.
     * @var array<string, string[]>
     */
    private const ALLOWED_TAGS = [
        'h1' => ['id', 'class', 'align'],
        'h2' => ['id', 'class', 'align'],
        'h3' => ['id', 'class', 'align'],
        'h4' => ['id', 'class', 'align'],
        'h5' => ['id', 'class', 'align'],
        'h6' => ['id', 'class', 'align'],
        'p' => ['align', 'class', 'style', 'id'],
        'br' => [],
        'hr' => ['class'],
        'ul' => ['class'],
        'ol' => ['class', 'start', 'type'],
        'li' => ['class', 'id'],
        'blockquote' => ['class', 'id'],
        'pre' => ['class', 'id'],
        'code' => ['class'],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'del' => [],
        's' => [],
        'a'  => ['href', 'id', 'name', 'class', 'title', 'target', 'rel', 'aria-hidden', 'tabindex'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'align', 'loading', 'style', 'class'],
        'table' => ['class', 'align', 'id'],
        'thead' => ['class'],
        'tbody' => ['class'],
        'tr' => ['class'],
        'th' => ['align', 'class', 'style', 'colspan', 'rowspan'],
        'td' => ['align', 'class', 'style', 'colspan', 'rowspan'],
        'input' => ['type', 'checked', 'disabled', 'class'],
        'span' => ['class', 'id', 'style'],
        'div' => ['class', 'id', 'style', 'align'],
        'details' => ['open', 'class', 'id'],
        'summary' => ['class', 'id'],
        'kbd' => ['class'],
        'sub' => [],
        'sup' => [],
        'mark' => [],
    ];

    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 10,
            'heading_permalink'  => [
                'html_class'          => 'anchor',
                'id_prefix'           => '',
                'fragment_prefix'     => '',
                'insert'              => 'before',
                'min_heading_level'   => 1,
                'max_heading_level'   => 6,
                'title'               => 'Permalink',
                'symbol'              => '',
                'aria_hidden'         => true,
                'apply_id_to_heading' => true,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new TaskListExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Ensure the input content is clean and valid UTF-8.
     * Detects common non-UTF-8 encodings or strips/replaces invalid byte sequences.
     */
    private function ensureUtf8(string $content): string
    {
        if (\mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Try detecting common non-UTF-8 encodings
        $detected = @\mb_detect_encoding($content, [
            'UTF-8',
            'Windows-1252',
            'ISO-8859-1',
            'ISO-8859-6',
            'Windows-1251',
            'GB18030',
            'BIG-5',
            'EUC-KR',
            'ASCII',
        ], true);

        if ($detected !== false && $detected !== 'UTF-8') {
            $converted = @\mb_convert_encoding($content, 'UTF-8', $detected);
            if ($converted !== false && \mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        // Fallback: convert UTF-8 to UTF-8 to strip/substitute invalid sequences
        $clean = @\mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        if (\mb_check_encoding($clean, 'UTF-8')) {
            return $clean;
        }

        $iconv = @\iconv('UTF-8', 'UTF-8//IGNORE', $content);
        if ($iconv !== false && \mb_check_encoding($iconv, 'UTF-8')) {
            return $iconv;
        }

        return (string) @\preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', '', $content);
    }

    /** Convert Markdown source to sanitized HTML for browser display with relative URL rewriting. */
    public function renderHtml(string $markdown, ?string $rawBaseUrl = null, ?string $blobBaseUrl = null): string
    {
        $markdown = $this->ensureUtf8($markdown);
        if (trim($markdown) === '') return '';

        try {
            $html = $this->converter->convert($markdown)->getContent();
        } catch (\Throwable) {
            $html = '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        $sanitized = $this->sanitizeHtml($html);

        if ($rawBaseUrl !== null || $blobBaseUrl !== null) {
            $sanitized = $this->rewriteRelativeUrls($sanitized, $rawBaseUrl, $blobBaseUrl);
        }

        return $sanitized;
    }

    /** Rewrite relative image sources and links to point to the repository raw/blob endpoints. */
    private function rewriteRelativeUrls(string $html, ?string $rawBaseUrl, ?string $blobBaseUrl): string
    {
        if ($html === '') return '';

        // Rewrite <img ... src="relative/path">
        if ($rawBaseUrl !== null && $rawBaseUrl !== '') {
            $rawBase = rtrim($rawBaseUrl, '/');
            $html = (string) preg_replace_callback(
                '/(<img\b[^>]*?\bsrc=["\'])([^"\']+)(["\'])/i',
                static function (array $m) use ($rawBase): string {
                    $src = trim($m[2]);
                    if ($src === '' || preg_match('/^(https?:|\/\/|\/|data:|#)/i', $src)) {
                        return $m[0];
                    }
                    $cleanSrc = ltrim($src, './');
                    return $m[1] . $rawBase . '/' . $cleanSrc . $m[3];
                },
                $html
            );
        }

        // Rewrite <a ... href="relative/path">
        if ($blobBaseUrl !== null && $blobBaseUrl !== '') {
            $blobBase = rtrim($blobBaseUrl, '/');
            $html = (string) preg_replace_callback(
                '/(<a\b[^>]*?\bhref=["\'])([^"\']+)(["\'])/i',
                static function (array $m) use ($blobBase): string {
                    $href = trim($m[2]);
                    if ($href === '' || preg_match('/^(https?:|\/\/|\/|mailto:|javascript:|#)/i', $href)) {
                        return $m[0];
                    }
                    // Intra-README anchor link: e.g. README.md#install or ./README.md#install
                    if (preg_match('/^\.?\/?readme(\.(md|markdown))?(#.+)$/i', $href, $matches)) {
                        return $m[1] . $matches[3] . $m[3];
                    }
                    $cleanHref = ltrim($href, './');
                    return $m[1] . $blobBase . '/' . $cleanHref . $m[3];
                },
                $html
            );
        }

        return $html;
    }

    /** Generate URL-safe slug for heading navigation (GitHub-compatible). */
    private function slugify(string $text): string
    {
        $text = strip_tags($text);
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Replace spaces and underscores with dashes
        $text = (string) preg_replace('/[\s_]+/u', '-', $text);
        // Remove special punctuation
        $text = (string) preg_replace('/[^\p{L}\p{N}\p{M}\-]+/u', '', $text);
        $text = trim($text, '-');

        return $text;
    }

    /** Ensure all headings have valid IDs for fast anchor navigation. */
    private function ensureHeadingIds(\DOMElement $body): void
    {
        $usedSlugs = [];
        $headings = [];

        foreach ($body->getElementsByTagName('*') as $el) {
            if (preg_match('/^h[1-6]$/i', $el->nodeName)) {
                $headings[] = $el;
            }
        }

        foreach ($headings as $h) {
            $id = trim($h->getAttribute('id'));
            if ($id === '') {
                $text = trim($h->textContent);
                $slug = $this->slugify($text);
                if ($slug === '') $slug = 'section';

                $baseSlug = $slug;
                $count = 1;
                while (isset($usedSlugs[$slug])) {
                    $slug = $baseSlug . '-' . $count;
                    $count++;
                }

                $h->setAttribute('id', $slug);
                $usedSlugs[$slug] = true;
            } else {
                $usedSlugs[$id] = true;
            }
        }
    }

    /** Strip every tag/attribute that is not on the whitelist. */
    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') return '';

        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><html><body>' . $encoded . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) return '';

        $this->sanitizeNode($body);
        $this->ensureHeadingIds($body);

        $output = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= (string) $dom->saveHTML($child);
        }

        return trim(mb_decode_numericentity($output, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
    }

    /** Recursively remove disallowed nodes and attributes. */
    private function sanitizeNode(\DOMNode $node): void
    {
        // Snapshot: childNodes is a live list and we mutate it below.
        foreach (iterator_to_array($node->childNodes) as $child) {
            // Drop comments, processing instructions, scripts and styles.
            if (
                $child instanceof \DOMComment
                || $child instanceof \DOMProcessingInstruction
                || ($child instanceof \DOMElement
                    && in_array(strtolower($child->nodeName), ['script', 'style', 'iframe', 'object', 'embed', 'form'], true))
            ) {
                $node->removeChild($child);
                continue;
            }

            if (!$child instanceof \DOMElement) continue; // Text nodes are escaped on output — safe.

            // Clean the subtree before deciding whether to keep the tag.
            $this->sanitizeNode($child);

            $tag = strtolower($child->nodeName);

            if (!array_key_exists($tag, self::ALLOWED_TAGS)) {
                // Unwrap: keep the (already sanitized) children, drop the tag.
                while ($child->firstChild !== null) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);

                $allowed = in_array($name, self::ALLOWED_TAGS[$tag], true);
                $safeUrl = !in_array($name, ['href', 'src'], true)
                    || $this->isSafeUrl($attr->nodeValue ?? '');
                $safeClass = $name !== 'class'
                    || preg_match('/^[a-zA-Z0-9_\-\s]+$/u', $attr->nodeValue ?? '') === 1;
                $safeId = !in_array($name, ['id', 'name'], true)
                    || preg_match('/^[a-zA-Z0-9_\-.:\x{0080}-\x{FFFF}]+$/u', $attr->nodeValue ?? '') === 1;
                $safeStyle = $name !== 'style'
                    || !preg_match('/(expression|javascript|behavior|vbscript|include-source|url\s*\()/i', $attr->nodeValue ?? '');

                if (!$allowed || !$safeUrl || !$safeClass || !$safeId || !$safeStyle) {
                    $child->removeAttribute($attr->nodeName);
                }
            }
        }
    }

    /** Accept relative URLs, anchors, and http/https/mailto schemes only. */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, "\0")) return false;

        $scheme = parse_url($url, PHP_URL_SCHEME);

        // No scheme -> relative URL or anchor -> safe.
        if ($scheme === null || $scheme === false) return true;

        return in_array(strtolower((string) $scheme), ['http', 'https', 'mailto'], true);
    }
}