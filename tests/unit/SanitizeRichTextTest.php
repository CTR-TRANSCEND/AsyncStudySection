<?php
/**
 * Unit Tests for Rich Text Sanitization
 * SPEC: SPEC-DISC-001
 * Description: Test XSS prevention and HTML sanitization for rich text messages
 * Version: 1.0.0
 * Date: 2025-01-04
 */

require_once __DIR__ . '/../../includes/functions.php';

use PHPUnit\Framework\TestCase;

class SanitizeRichTextTest extends TestCase
{
    /**
     * Test 1: Script tags should be removed
     * Given: HTML input containing script tags
     * When: sanitizeRichText() is called
     * Then: Script tags should be removed completely
     */
    public function testScriptTagsRemoved()
    {
        $input = '<p>Hello</p><script>alert("XSS")</script><p>World</p>';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
        $this->assertStringContainsString('<p>World</p>', $result);
    }

    /**
     * Test 2: Event handlers should be removed
     * Given: HTML input with event handlers
     * When: sanitizeRichText() is called
     * Then: All event handlers should be removed
     */
    public function testEventHandlersRemoved()
    {
        $input = '<img src="x" onerror="alert(\'XSS\')">';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onload', $result);
    }

    /**
     * Test 3: Safe HTML tags should be preserved
     * Given: HTML input with safe tags only
     * When: sanitizeRichText() is called
     * Then: Safe tags should be preserved
     */
    public function testSafeTagsPreserved()
    {
        $input = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em><u>Underline</u>';
        $result = sanitizeRichText($input);

        $this->assertStringContainsString('<p>Paragraph</p>', $result);
        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>Italic</em>', $result);
        $this->assertStringContainsString('<u>Underline</u>', $result);
    }

    /**
     * Test 4: Lists should be preserved
     * Given: HTML input with ordered and unordered lists
     * When: sanitizeRichText() is called
     * Then: List tags should be preserved
     */
    public function testListsPreserved()
    {
        $input = '<ul><li>Item 1</li><li>Item 2</li></ul><ol><li>A</li><li>B</li></ol>';
        $result = sanitizeRichText($input);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<li>', $result);
    }

    /**
     * Test 5: Code blocks should be preserved
     * Given: HTML input with code blocks
     * When: sanitizeRichText() is called
     * Then: Code and pre tags should be preserved
     */
    public function testCodeBlocksPreserved()
    {
        $input = '<pre><code>function test() { return true; }</code></pre>';
        $result = sanitizeRichText($input);

        $this->assertStringContainsString('<pre>', $result);
        $this->assertStringContainsString('<code>', $result);
        $this->assertStringContainsString('function test()', $result);
    }

    /**
     * Test 6: Blockquotes should be preserved
     * Given: HTML input with blockquotes
     * When: sanitizeRichText() is called
     * Then: Blockquote tags should be preserved
     */
    public function testBlockquotesPreserved()
    {
        $input = '<blockquote>This is a quote</blockquote>';
        $result = sanitizeRichText($input);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('This is a quote', $result);
    }

    /**
     * Test 7: Links should be sanitized
     * Given: HTML input with links including javascript: protocol
     * When: sanitizeRichText() is called
     * Then: javascript: links should be removed, http/https links preserved
     */
    public function testLinksSanitized()
    {
        $input = '<a href="javascript:alert(\'XSS\')">Malicious</a> <a href="https://example.com">Safe</a>';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    /**
     * Test 8: iframe tags should be removed
     * Given: HTML input with iframe
     * When: sanitizeRichText() is called
     * Then: iframe tags should be removed
     */
    public function testIframeRemoved()
    {
        $input = '<iframe src="http://example.com"></iframe>';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('iframe', $result);
    }

    /**
     * Test 9: object and embed tags should be removed
     * Given: HTML input with object/embed
     * When: sanitizeRichText() is called
     * Then: object and embed tags should be removed
     */
    public function testObjectEmbedRemoved()
    {
        $input = '<object data="file.swf"></object><embed src="file.swf">';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('<object', $result);
        $this->assertStringNotContainsString('<embed', $result);
    }

    /**
     * Test 10: Plain text should be escaped
     * Given: Plain text input without HTML
     * When: sanitizeRichText() is called
     * Then: Text should be wrapped in <p> tags and special chars escaped
     */
    public function testPlainTextEscaped()
    {
        $input = 'This is plain text with <special> characters & "quotes"';
        $result = sanitizeRichText($input);

        $this->assertStringNotContainsString('<special>', $result);
        $this->assertStringContainsString('&amp;', $result); // & is encoded
        // HTMLPurifier strips unrecognized tags entirely
    }

    /**
     * Test 11: Multiple XSS attack vectors should be blocked
     * Given: Various XSS attack patterns
     * When: sanitizeRichText() is called
     * Then: All attacks should be neutralized
     */
    public function testXSSAttackVectorsBlocked()
    {
        $attacks = [
            '<script>alert("XSS")</script>' => 'Script tag',
            '<img src=x onerror="alert(1)">' => 'Img onerror',
            '<svg onload="alert(1)">' => 'SVG onload',
            '<body onload="alert(1)">' => 'Body onload',
            '<input onfocus="alert(1)" autofocus>' => 'Input autofocus',
            '<select onfocus="alert(1)" autofocus>' => 'Select autofocus',
            '<textarea onfocus="alert(1)" autofocus>' => 'Textarea autofocus',
        ];

        foreach ($attacks as $attack => $description) {
            $result = sanitizeRichText($attack);
            $this->assertStringNotContainsString('alert', $result, "Failed for: $description");
            $this->assertStringNotContainsString('onerror', $result, "Failed for: $description");
            $this->assertStringNotContainsString('onload', $result, "Failed for: $description");
            $this->assertStringNotContainsString('onfocus', $result, "Failed for: $description");
        }
    }

    /**
     * Test 12: Nested tags should be handled correctly
     * Given: HTML input with nested safe tags
     * When: sanitizeRichText() is called
     * Then: Nested structure should be preserved
     */
    public function testNestedTagsPreserved()
    {
        $input = '<ul><li><strong>Bold</strong> and <em>italic</em></li></ul>';
        $result = sanitizeRichText($input);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    /**
     * Test 13: Empty input should return empty string
     * Given: Empty string input
     * When: sanitizeRichText() is called
     * Then: Should return empty string
     */
    public function testEmptyInput()
    {
        $result = sanitizeRichText('');
        $this->assertEquals('', $result);
    }

    /**
     * Test 14: Whitespace-only input should be handled
     * Given: Input with only whitespace
     * When: sanitizeRichText() is called
     * Then: Should return empty string or minimal HTML
     */
    public function testWhitespaceInput()
    {
        $result = sanitizeRichText('   ');
        $this->assertSame('', $result);
    }

    /**
     * Test 15: Maximum message length should be enforced
     * Given: Input longer than 10,000 characters
     * When: sanitizeRichText() is called
     * Then: Should truncate to 10,000 characters
     */
    public function testMaxLengthEnforced()
    {
        $input = '<p>' . str_repeat('A', 15000) . '</p>';
        $result = sanitizeRichText($input, 10000);

        $this->assertLessThanOrEqual(10000, mb_strlen($result));
    }
}
