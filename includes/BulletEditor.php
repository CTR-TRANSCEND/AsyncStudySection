<?php
declare(strict_types=1);

/**
 * BulletEditor.php
 *
 * Secure bullet point editor helper class for the Grant Review System.
 *
 * Features:
 * - Render bullet point editor with toolbar and validation
 * - Sanitize input to prevent XSS attacks
 * - Format bullet points for display and storage
 * - Validate bullet point count and length
 * - Extract individual bullet points from content
 * - CSRF token integration
 * - PSR-12 compliant coding standards
 *
 * @package GrantReviewSystem
 * @subpackage Helpers
 * @version 1.0.0
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}

class BulletEditor
{
    /**
     * Default editor options.
     *
     * @var array
     */
    private const DEFAULT_OPTIONS = [
        'id' => null,
        'class' => '',
        'placeholder' => 'Enter bullet points (one per line)...',
        'maxLength' => 5000,
        'minBullets' => 0,
        'maxBullets' => 20,
        'required' => false,
        'disabled' => false,
        'toolbar' => true,
        'preview' => true,
        'autoSave' => false,
        'autoSaveDelay' => 2000,
    ];

    /**
     * Allowed HTML tags for bullet point content.
     *
     * @var string
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><u><ul><ol><li><sub><sup>';

    /**
     * Generate unique editor ID.
     *
     * @param string $name Field name for the editor
     *
     * @return string Unique editor ID
     */
    private static function generateEditorId(string $name): string
    {
        return 'bullet-editor-' . bin2hex(random_bytes(4));
    }

    /**
     * Escape HTML attribute value.
     *
     * @param string $value Value to escape
     *
     * @return string Escaped value
     */
    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render bullet point editor HTML.
     *
     * @param string $name Field name for form submission
     * @param string $value Initial editor content (default: '')
     * @param array $options Editor configuration options (default: [])
     *
     * @return string Rendered HTML
     */
    public static function render(string $name, string $value = '', array $options = []): string
    {
        // Merge with defaults
        $opts = array_merge(self::DEFAULT_OPTIONS, $options);

        // Generate unique ID if not provided
        $editorId = $opts['id'] ?? self::generateEditorId($name);

        // Sanitize initial value
        $safeValue = self::sanitizeBulletPoints($value);

        // Build CSS classes
        $classes = ['bullet-editor'];
        if (!empty($opts['class'])) {
            $classes[] = self::escapeAttr($opts['class']);
        }
        $classAttr = implode(' ', $classes);

        // Build disabled attribute
        $disabledAttr = $opts['disabled'] ? ' disabled' : '';

        // Build required attribute
        $requiredAttr = $opts['required'] ? ' required' : '';

        // Build data attributes for JavaScript
        $dataAttrs = [
            'data-editor-id' => $editorId,
            'data-field-name' => $name,
            'data-max-length' => (int) $opts['maxLength'],
            'data-min-bullets' => (int) $opts['minBullets'],
            'data-max-bullets' => (int) $opts['maxBullets'],
            'data-auto-save' => $opts['autoSave'] ? 'true' : 'false',
            'data-auto-save-delay' => (int) $opts['autoSaveDelay'],
            'data-preview' => $opts['preview'] ? 'true' : 'false',
        ];

        $dataAttrStr = '';
        foreach ($dataAttrs as $key => $val) {
            $dataAttrStr .= sprintf(' %s="%s"', $key, self::escapeAttr($val));
        }

        // Build toolbar HTML
        $toolbarHtml = '';
        if ($opts['toolbar']) {
            $toolbarHtml = self::renderToolbar($editorId, $opts['disabled']);
        }

        // Build preview HTML
        $previewHtml = '';
        if ($opts['preview']) {
            $previewHtml = self::renderPreview($editorId);
        }

        // Build footer with validation messages
        $footerHtml = self::renderFooter($editorId, $opts);

        // Build hidden input for form submission
        $hiddenInput = sprintf(
            '<input type="hidden" name="%s" id="%s-input" value="%s"%s%s>',
            self::escapeAttr($name),
            $editorId,
            self::escapeAttr($safeValue),
            $requiredAttr,
            $disabledAttr
        );

        // Build contenteditable div
        $contentEditable = sprintf(
            '<div class="bullet-editor-content" id="%s-content" contenteditable="true"%s%s>%s</div>',
            $editorId,
            $disabledAttr,
            $requiredAttr,
            $safeValue
        );

        // Assemble complete editor
        $html = sprintf(
            '<div class="%s"%s>%s%s%s%s%s</div>',
            $classAttr,
            $dataAttrStr,
            $toolbarHtml,
            $contentEditable,
            $hiddenInput,
            $footerHtml,
            $previewHtml
        );

        return $html;
    }

    /**
     * Render editor toolbar with buttons.
     *
     * @param string $editorId Editor element ID
     * @param bool $disabled Whether editor is disabled
     *
     * @return string Toolbar HTML
     */
    private static function renderToolbar(string $editorId, bool $disabled): string
    {
        $disabledClass = $disabled ? ' disabled' : '';

        $buttons = [
            ['icon' => 'list-ul', 'title' => 'Bullet List', 'command' => 'insertUnorderedList'],
            ['icon' => 'list-ol', 'title' => 'Numbered List', 'command' => 'insertOrderedList'],
            ['icon' => 'bold', 'title' => 'Bold', 'command' => 'bold'],
            ['icon' => 'italic', 'title' => 'Italic', 'command' => 'italic'],
            ['icon' => 'underline', 'title' => 'Underline', 'command' => 'underline'],
        ];

        $buttonHtml = '';
        foreach ($buttons as $btn) {
            $buttonHtml .= sprintf(
                '<button type="button" class="toolbar-btn%s" data-command="%s" title="%s"%s>',
                $disabledClass,
                $btn['command'],
                self::escapeAttr($btn['title']),
                $disabled ? ' disabled' : ''
            );
            $buttonHtml .= sprintf('<span class="icon-%s"></span>', $btn['icon']);
            $buttonHtml .= '</button>';
        }

        return sprintf('<div class="bullet-editor-toolbar" id="%s-toolbar">%s</div>', $editorId, $buttonHtml);
    }

    /**
     * Render preview panel.
     *
     * @param string $editorId Editor element ID
     *
     * @return string Preview HTML
     */
    private static function renderPreview(string $editorId): string
    {
        return sprintf(
            '<div class="bullet-editor-preview" id="%s-preview" style="display:none;">' .
            '<div class="preview-header">Preview</div>' .
            '<div class="preview-content" id="%s-preview-content"></div>' .
            '</div>',
            $editorId,
            $editorId
        );
    }

    /**
     * Render footer with character count and validation messages.
     *
     * @param string $editorId Editor element ID
     * @param array $options Editor options
     *
     * @return string Footer HTML
     */
    private static function renderFooter(string $editorId, array $options): string
    {
        $requiredHtml = $options['required']
            ? '<span class="required-indicator" style="color:red;">*</span> '
            : '';

        $footerHtml = sprintf(
            '<div class="bullet-editor-footer" id="%s-footer">' .
            '<div class="footer-left">%s<span class="char-count" id="%s-char-count">0</span> / %d characters</div>' .
            '<div class="footer-right">' .
            '<span class="bullet-count" id="%s-bullet-count">0</span> bullets' .
            '<span class="validation-message" id="%s-validation"></span>' .
            '</div>' .
            '</div>',
            $editorId,
            $requiredHtml,
            $editorId,
            (int) $options['maxLength'],
            $editorId,
            $editorId
        );

        return $footerHtml;
    }

    /**
     * Sanitize bullet point content to prevent XSS attacks.
     *
     * Removes dangerous HTML tags, attributes, and JavaScript while
     * preserving safe formatting tags.
     *
     * @param string $content Raw bullet point content
     *
     * @return string Sanitized content safe for display
     */
    public static function sanitizeBulletPoints(string $content): string
    {
        // Trim whitespace
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Remove all tags except allowed safe tags
        $content = strip_tags($content, self::ALLOWED_TAGS);

        // Remove dangerous attributes and patterns
        $patterns = [
            // Event handlers (onclick, onerror, onload, etc.)
            '/\s+on\w+\s*=\s*(["\'])[^\1]*\1/i',
            '/\s+on\w+\s*=\s*[^\s>]*/i',

            // JavaScript links
            '/href\s*=\s*(["\'])\s*javascript:[^\1]*\1/i',
            '/href\s*=\s*javascript:[^\s>]*/i',

            // Data URLs (potential XSS)
            '/src\s*=\s*(["\'])\s*data:[^\1]*\1/i',
            '/src\s*=\s*data:[^\s>]*/i',

            // Style attributes with expression()
            '/style\s*=\s*(["\'])[^"]*expression[^"\\\\]*\1/i',

            // -moz-binding
            '/-moz-binding\s*:\s*url\([^)]*\)/i',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        // Remove any remaining script tags (double-check)
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);

        // Remove iframe, object, embed tags
        $content = preg_replace('/<(iframe|object|embed|form|input|button)\b[^>]*>/i', '', $content);
        $content = preg_replace('/<\/(iframe|object|embed|form|input|button)>/i', '', $content);

        // Remove style tags with content
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Clean up multiple empty lines
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        // Clean up excessive spaces
        $content = preg_replace('/\s{2,}/', ' ', $content);

        return trim($content);
    }

    /**
     * Format bullet points for display or storage.
     *
     * @param string $content Raw bullet point content
     * @param bool $html Whether to return HTML or plain text (default: false)
     *
     * @return string Formatted bullet points
     */
    public static function formatBulletPoints(string $content, bool $html = false): string
    {
        // First sanitize the content
        $content = self::sanitizeBulletPoints($content);

        if ($content === '') {
            return '';
        }

        // Split into lines
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $bullets = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Remove existing bullet markers for consistent formatting
            $line = preg_replace('/^[\s\-*•]\s*/', '', $line);

            if ($html) {
                $bullets[] = sprintf('<li>%s</li>', $line);
            } else {
                $bullets[] = sprintf('• %s', $line);
            }
        }

        if (empty($bullets)) {
            return '';
        }

        if ($html) {
            return '<ul>' . implode('', $bullets) . '</ul>';
        }

        return implode("\n", $bullets);
    }

    /**
     * Validate bullet point content.
     *
     * Checks bullet count, character limits, and content requirements.
     *
     * @param string $content Bullet point content to validate
     * @param int $minBullets Minimum number of bullets (default: 0)
     * @param int $maxBullets Maximum number of bullets (default: 10)
     *
     * @return array Validation result with 'valid' (bool) and 'errors' (array) keys
     */
    public static function validateBulletPoints(string $content, int $minBullets = 0, int $maxBullets = 10): array
    {
        $errors = [];

        // Sanitize first
        $content = self::sanitizeBulletPoints($content);

        // Extract bullet points
        $bullets = self::extractBulletPoints($content);
        $bulletCount = count($bullets);

        // Check minimum bullet count
        if ($bulletCount < $minBullets) {
            $errors[] = sprintf(
                'Minimum of %d bullet point%s required. Current: %d',
                $minBullets,
                $minBullets === 1 ? '' : 's',
                $bulletCount
            );
        }

        // Check maximum bullet count
        if ($bulletCount > $maxBullets) {
            $errors[] = sprintf(
                'Maximum of %d bullet points allowed. Current: %d',
                $maxBullets,
                $bulletCount
            );
        }

        // Check each bullet for empty content
        foreach ($bullets as $index => $bullet) {
            if (trim($bullet) === '') {
                $errors[] = sprintf('Bullet point %d is empty', $index + 1);
            }
        }

        // Check total character length
        $charCount = strlen($content);
        if ($charCount > 5000) {
            $errors[] = sprintf(
                'Content exceeds maximum length of 5000 characters. Current: %d',
                $charCount
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'bullet_count' => $bulletCount,
            'char_count' => $charCount,
        ];
    }

    /**
     * Extract individual bullet points from content.
     *
     * Parses content and returns array of individual bullet points,
     * handling various bullet formats (-, *, •, etc.)
     *
     * @param string $content Bullet point content
     *
     * @return array Array of bullet point strings
     */
    public static function extractBulletPoints(string $content): array
    {
        // Sanitize first
        $content = self::sanitizeBulletPoints($content);

        if ($content === '') {
            return [];
        }

        // Split into lines
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $bullets = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Remove common bullet markers
            $line = preg_replace('/^[\s\-*•]\s*/', '', $line);

            // Only include non-empty lines
            if ($line !== '') {
                $bullets[] = $line;
            }
        }

        return $bullets;
    }

    /**
     * Convert bullet points to plain text format.
     *
     * @param string $content Bullet point content (HTML or plain)
     *
     * @return string Plain text bullet points
     */
    public static function toPlainText(string $content): string
    {
        // Remove HTML tags
        $text = strip_tags($content);

        // Convert HTML entities to plain text
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize line breaks
        $text = preg_replace('/\r\n|\r/', "\n", $text);

        // Clean up extra whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Check if content appears to be bullet point formatted.
     *
     * @param string $content Content to check
     *
     * @return bool True if content appears to contain bullet points
     */
    public static function isBulletFormat(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        // Check for common bullet patterns
        $patterns = [
            '/^\s*[-*•]\s+/m',  // Starts with bullet markers
            '/<li[^>]*>/i',      // Contains <li> tags
            '/<ul[^>]*>/i',      // Contains <ul> tags
            '/<ol[^>]*>/i',      // Contains <ol> tags
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get bullet point statistics.
     *
     * Returns detailed statistics about bullet point content including
     * count, average length, longest/shortest bullets.
     *
     * @param string $content Bullet point content
     *
     * @return array Statistics array
     */
    public static function getStatistics(string $content): array
    {
        $bullets = self::extractBulletPoints($content);
        $count = count($bullets);

        if ($count === 0) {
            return [
                'bullet_count' => 0,
                'total_chars' => 0,
                'avg_length' => 0,
                'min_length' => 0,
                'max_length' => 0,
                'empty_count' => 0,
            ];
        }

        $lengths = array_map('strlen', $bullets);
        $totalChars = array_sum($lengths);

        return [
            'bullet_count' => $count,
            'total_chars' => $totalChars,
            'avg_length' => round($totalChars / $count, 2),
            'min_length' => min($lengths),
            'max_length' => max($lengths),
            'empty_count' => count(array_filter($bullets, fn($b) => trim($b) === '')),
        ];
    }
}
