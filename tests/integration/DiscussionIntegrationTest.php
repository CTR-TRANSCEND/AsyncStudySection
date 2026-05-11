<?php
/**
 * Integration Tests for Discussion System
 * SPEC: SPEC-DISC-001
 * Description: Integration tests for discussion features
 * Version: 1.0.0
 * Date: 2025-01-04
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/DiscussionHelper.php';
require_once __DIR__ . '/../../includes/FileUploadHandler.php';
require_once __DIR__ . '/../../includes/DiscussionModerator.php';

use PHPUnit\Framework\TestCase;

class DiscussionIntegrationTest extends TestCase
{
    private $db;
    private $testUserId;
    private $testApplicationId;
    private $testMessageId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();

        // Clean up any leftover test data from previous runs
        $this->db->prepare("DELETE FROM audit_log WHERE changed_by IN (SELECT id FROM users WHERE username = 'test_reviewer_disc')")->execute();
        $this->db->prepare("DELETE FROM discussion_message_reads WHERE user_id IN (SELECT id FROM users WHERE username = 'test_reviewer_disc')")->execute();
        $this->db->prepare("DELETE FROM discussion_exports WHERE user_id IN (SELECT id FROM users WHERE username = 'test_reviewer_disc')")->execute();
        $this->db->prepare("DELETE FROM discussion_messages WHERE user_id IN (SELECT id FROM users WHERE username = 'test_reviewer_disc')")->execute();
        $this->db->prepare("DELETE FROM assignments WHERE reviewer_id IN (SELECT id FROM users WHERE username = 'test_reviewer_disc')")->execute();
        $this->db->prepare("DELETE FROM applications WHERE grant_id = 'TEST-001'")->execute();
        $this->db->prepare("DELETE FROM users WHERE username = 'test_reviewer_disc'")->execute();

        // Create test user
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role)
            VALUES ('test_reviewer_disc', '\$2y\$10\$testhash', 'Test Reviewer', 'test_disc@example.com', 'reviewer')
        ");
        $stmt->execute();
        $this->testUserId = $this->db->lastInsertId();

        // Create test application
        $stmt = $this->db->prepare("
            INSERT INTO applications (applicant_name, grant_id, application_title, grant_type)
            VALUES ('Test Applicant', 'TEST-001', 'Test Application for Discussion', 'TRANSCEND Pilot')
        ");
        $stmt->execute();
        $this->testApplicationId = $this->db->lastInsertId();

        // Create assignment
        $stmt = $this->db->prepare("
            INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
            VALUES (?, ?, 'Reviewer A')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);
    }

    protected function tearDown(): void
    {
        // Clean up test data (audit_log first due to foreign key constraints)
        $stmt = $this->db->prepare("DELETE FROM audit_log WHERE changed_by = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM discussion_exports WHERE user_id = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM discussion_message_reads WHERE user_id = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM uploaded_files WHERE uploaded_by = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM discussion_messages WHERE user_id = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM assignments WHERE reviewer_id = ?");
        $stmt->execute([$this->testUserId]);

        $stmt = $this->db->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->execute([$this->testApplicationId]);

        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$this->testUserId]);
    }

    /**
     * Test 1: Create message with rich text
     */
    public function testCreateRichTextMessage()
    {
        $message = "<p>This is <strong>bold</strong> and <em>italic</em> text.</p>";
        $sanitized = sanitizeRichText($message);

        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message, message_html)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([
            $this->testApplicationId,
            $this->testUserId,
            $sanitized,
            $sanitized
        ]);

        $this->assertTrue($result);
        $this->testMessageId = $this->db->lastInsertId();
        $this->assertGreaterThan(0, $this->testMessageId);
    }

    /**
     * Test 2: Search messages
     */
    public function testSearchMessages()
    {
        // Create test messages
        $messages = ['This is about budget', 'This is about timeline', 'Another budget message'];
        foreach ($messages as $msg) {
            $stmt = $this->db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$this->testApplicationId, $this->testUserId, $msg]);
        }

        // Search for 'budget'
        $results = DiscussionHelper::searchMessages($this->db, $this->testApplicationId, 'budget');

        $this->assertGreaterThanOrEqual(2, count($results));
        foreach ($results as $result) {
            $this->assertStringContainsString('budget', $result['message']);
        }
    }

    /**
     * Test 3: Mark messages as read
     {
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message)
            VALUES (?, ?, 'Test message')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);
        $messageId = $this->db->lastInsertId();

        // Create another user to mark as read
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role)
            VALUES ('test_reviewer2', 'hash', 'Test Reviewer 2', 'test2@example.com', 'reviewer')
        ");
        $stmt->execute();
        $otherUserId = $this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
            VALUES (?, ?, 'Reviewer B')
        ");
        $stmt->execute([$this->testApplicationId, $otherUserId]);

        // Mark as read
        $marked = DiscussionHelper::markAsRead($this->db, $otherUserId, $this->testApplicationId, [$messageId]);

        $this->assertEquals(1, $marked);

        // Verify read status
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM discussion_message_reads
            WHERE user_id = ? AND message_id = ?
        ");
        $stmt->execute([$otherUserId, $messageId]);
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count);

        // Clean up
        $stmt = $this->db->prepare("DELETE FROM discussion_message_reads WHERE user_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM assignments WHERE reviewer_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$otherUserId]);
    }

    /**
     * Test 4: Get unread counts
     */
    public function testGetUnreadCounts()
    {
        // Create messages from another user
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role)
            VALUES ('test_reviewer3', 'hash', 'Test Reviewer 3', 'test3@example.com', 'reviewer')
        ");
        $stmt->execute();
        $otherUserId = $this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
            VALUES (?, ?, 'Reviewer B')
        ");
        $stmt->execute([$this->testApplicationId, $otherUserId]);

        // Create messages from other user
        for ($i = 0; $i < 3; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message)
                VALUES (?, ?, 'Message $i')
            ");
            $stmt->execute([$this->testApplicationId, $otherUserId]);
        }

        // Get unread counts for test user
        $counts = DiscussionHelper::getUnreadCounts($this->db, $this->testUserId);

        $this->assertArrayHasKey($this->testApplicationId, $counts);
        $this->assertEquals(3, $counts[$this->testApplicationId]);

        // Clean up
        $stmt = $this->db->prepare("DELETE FROM discussion_messages WHERE user_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM assignments WHERE reviewer_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$otherUserId]);
    }

    /**
     * Test 5: Mark all as read
     */
    public function testMarkAllAsRead()
    {
        // Create messages from another user
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role)
            VALUES ('test_reviewer4', 'hash', 'Test Reviewer 4', 'test4@example.com', 'reviewer')
        ");
        $stmt->execute();
        $otherUserId = $this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
            VALUES (?, ?, 'Reviewer B')
        ");
        $stmt->execute([$this->testApplicationId, $otherUserId]);

        // Create messages
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message)
                VALUES (?, ?, 'Message $i')
            ");
            $stmt->execute([$this->testApplicationId, $otherUserId]);
        }

        // Mark all as read
        $marked = DiscussionHelper::markAllAsRead($this->db, $this->testUserId, $this->testApplicationId);

        $this->assertEquals(5, $marked);

        // Verify no unread messages
        $counts = DiscussionHelper::getUnreadCounts($this->db, $this->testUserId);
        $this->assertEquals(0, $counts[$this->testApplicationId] ?? 0);

        // Clean up
        $stmt = $this->db->prepare("DELETE FROM discussion_message_reads WHERE user_id = ?");
        $stmt->execute([$this->testUserId]);
        $stmt = $this->db->prepare("DELETE FROM discussion_messages WHERE user_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM assignments WHERE reviewer_id = ?");
        $stmt->execute([$otherUserId]);
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$otherUserId]);
    }

    /**
     * Test 6: Export discussion as JSON
     */
    public function testExportDiscussionJson()
    {
        // Create test messages
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message)
            VALUES (?, ?, 'Test message for export')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);

        // Mock admin user for export - set both DB and session
        $stmt = $this->db->prepare("
            UPDATE users SET role = 'admin' WHERE id = ?
        ");
        $stmt->execute([$this->testUserId]);
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = 'test_reviewer_disc';

        try {
            $export = DiscussionHelper::exportDiscussion($this->db, $this->testApplicationId, 'json');

            $this->assertArrayHasKey('filepath', $export);
            $this->assertArrayHasKey('filename', $export);
            $this->assertArrayHasKey('filesize', $export);
            $this->assertArrayHasKey('format', $export);
            $this->assertEquals('json', $export['format']);

            // Verify file exists
            $this->assertFileExists($export['filepath']);

            // Clean up export file
            if (file_exists($export['filepath'])) {
                unlink($export['filepath']);
            }

            // Clean up export record
            $stmt = $this->db->prepare("DELETE FROM discussion_exports WHERE file_path = ?");
            $stmt->execute([$export['filepath']]);

            // Clean up audit log
            $stmt = $this->db->prepare("DELETE FROM audit_log WHERE table_name = 'discussion_exports'");
            $stmt->execute();
        } finally {
            // Reset role
            $stmt = $this->db->prepare("UPDATE users SET role = 'reviewer' WHERE id = ?");
            $stmt->execute([$this->testUserId]);
            $_SESSION['role'] = 'reviewer';
        }
    }

    /**
     * Test 7: Soft delete message (admin only)
     */
    public function testSoftDeleteMessage()
    {
        // Create test message
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message)
            VALUES (?, ?, 'Message to delete')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);
        $messageId = $this->db->lastInsertId();

        // Make user admin in DB and session
        $stmt = $this->db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$this->testUserId]);
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = 'test_reviewer_disc';

        try {
            // Soft delete
            $result = DiscussionModerator::softDeleteMessage($this->db, $messageId, $this->testUserId);
            $this->assertTrue($result);

            // Verify soft delete
            $stmt = $this->db->prepare("SELECT is_deleted, deleted_by FROM discussion_messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();

            $this->assertEquals(1, $message['is_deleted']);
            $this->assertEquals($this->testUserId, $message['deleted_by']);

        } finally {
            // Reset role
            $stmt = $this->db->prepare("UPDATE users SET role = 'reviewer' WHERE id = ?");
            $stmt->execute([$this->testUserId]);
            $_SESSION['role'] = 'reviewer';
        }
    }

    /**
     * Test 8: Pin message (admin only)
     */
    public function testPinMessage()
    {
        // Create test message
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message)
            VALUES (?, ?, 'Important message')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);
        $messageId = $this->db->lastInsertId();

        // Make user admin in DB and session
        $stmt = $this->db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$this->testUserId]);
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = 'test_reviewer_disc';

        try {
            // Pin message
            $result = DiscussionModerator::pinMessage($this->db, $messageId, $this->testUserId);
            $this->assertTrue($result);

            // Verify pin
            $stmt = $this->db->prepare("SELECT is_pinned, pinned_at FROM discussion_messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();

            $this->assertEquals(1, $message['is_pinned']);
            $this->assertNotNull($message['pinned_at']);

        } finally {
            // Reset role
            $stmt = $this->db->prepare("UPDATE users SET role = 'reviewer' WHERE id = ?");
            $stmt->execute([$this->testUserId]);
            $_SESSION['role'] = 'reviewer';
        }
    }

    /**
     * Test 9: Lock discussion (admin only)
     */
    public function testLockDiscussion()
    {
        // Make user admin in DB and session
        $stmt = $this->db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$this->testUserId]);
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = 'test_reviewer_disc';

        try {
            // Lock discussion
            $result = DiscussionModerator::lockDiscussion($this->db, $this->testApplicationId, 'Test lock', $this->testUserId);
            $this->assertTrue($result);

            // Verify lock
            $stmt = $this->db->prepare("SELECT discussion_locked, discussion_locked_by FROM applications WHERE id = ?");
            $stmt->execute([$this->testApplicationId]);
            $app = $stmt->fetch();

            $this->assertEquals(1, $app['discussion_locked']);
            $this->assertEquals($this->testUserId, $app['discussion_locked_by']);

        } finally {
            // Unlock and reset role
            DiscussionModerator::unlockDiscussion($this->db, $this->testApplicationId, $this->testUserId);
            $stmt = $this->db->prepare("UPDATE users SET role = 'reviewer' WHERE id = ?");
            $stmt->execute([$this->testUserId]);
            $_SESSION['role'] = 'reviewer';
        }
    }

    /**
     * Test 10: File upload validation
     */
    public function testFileUploadValidation()
    {
        // Enable test mode to skip is_uploaded_file check
        FileUploadHandler::$skipUploadCheck = true;

        try {
            // Create a temporary test file
            $tempFile = tmpfile();
            fwrite($tempFile, 'Test content');
            $metaData = stream_get_meta_data($tempFile);
            $tempPath = $metaData['uri'];

            $file = [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => $tempPath,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen('Test content')
            ];

            $error = '';
            $result = FileUploadHandler::validateFile($file, $error);

            $this->assertNotNull($result, "Validation failed: $error");
            $this->assertEquals('text/plain', $result['mime_type']);
            $this->assertEquals('txt', $result['extension']);

            fclose($tempFile);
        } finally {
            FileUploadHandler::$skipUploadCheck = false;
        }
    }

    /**
     * Test 11: File upload blocks executables
     */
    public function testFileUploadBlocksExecutables()
    {
        // Enable test mode to skip is_uploaded_file check
        FileUploadHandler::$skipUploadCheck = true;

        try {
            // Create a temp file to simulate an .exe upload
            $tempPath = tempnam(sys_get_temp_dir(), 'test_exe_');
            file_put_contents($tempPath, 'MZ executable content');

            $file = [
                'name' => 'test.exe',
                'type' => 'application/x-msdownload',
                'tmp_name' => $tempPath,
                'error' => UPLOAD_ERR_OK,
                'size' => 1000
            ];

            $error = '';
            $result = FileUploadHandler::validateFile($file, $error);

            $this->assertNull($result);
            $this->assertStringContainsString('not allowed', $error);

            @unlink($tempPath);
        } finally {
            FileUploadHandler::$skipUploadCheck = false;
        }
    }

    /**
     * Test 12: File upload size limit
     */
    public function testFileUploadSizeLimit()
    {
        // Enable test mode to skip is_uploaded_file check
        FileUploadHandler::$skipUploadCheck = true;

        try {
            // Create a temp file
            $tempPath = tempnam(sys_get_temp_dir(), 'test_large_');
            file_put_contents($tempPath, 'small content');

            $file = [
                'name' => 'large.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tempPath,
                'error' => UPLOAD_ERR_OK,
                'size' => 15000000 // 15MB, exceeds 10MB limit
            ];

            $error = '';
            $result = FileUploadHandler::validateFile($file, $error);

            $this->assertNull($result);
            $this->assertStringContainsString('10MB', $error);

            @unlink($tempPath);
        } finally {
            FileUploadHandler::$skipUploadCheck = false;
        }
    }

    /**
     * Test 13: Threaded replies
     */
    public function testThreadedReplies()
    {
        // Create parent message
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message)
            VALUES (?, ?, 'Parent message')
        ");
        $stmt->execute([$this->testApplicationId, $this->testUserId]);
        $parentId = $this->db->lastInsertId();

        // Create reply
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message, parent_message_id, thread_path)
            VALUES (?, ?, 'Reply message', ?, ?)
        ");
        $threadPath = $parentId . '/' . ($parentId + 1000);
        $stmt->execute([$this->testApplicationId, $this->testUserId, $parentId, $threadPath]);
        $replyId = $this->db->lastInsertId();

        $this->assertGreaterThan(0, $replyId);

        // Verify thread structure
        $stmt = $this->db->prepare("SELECT parent_message_id, thread_path FROM discussion_messages WHERE id = ?");
        $stmt->execute([$replyId]);
        $reply = $stmt->fetch();

        $this->assertEquals($parentId, $reply['parent_message_id']);
        $this->assertEquals($threadPath, $reply['thread_path']);
    }

    /**
     * Test 14: Search with filters
     */
    public function testSearchWithFilters()
    {
        // Create test messages with different timestamps
        $timestamps = [
            date('Y-m-d H:i:s', strtotime('-2 days')),
            date('Y-m-d H:i:s', strtotime('-5 days')),
            date('Y-m-d H:i:s', strtotime('-10 days'))
        ];

        foreach ($timestamps as $ts) {
            $stmt = $this->db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message, created_at)
                VALUES (?, ?, 'Test message', ?)
            ");
            $stmt->execute([$this->testApplicationId, $this->testUserId, $ts]);
        }

        // Search with date filter
        $filters = ['date_from' => date('Y-m-d', strtotime('-7 days'))];
        $results = DiscussionHelper::searchMessages($this->db, $this->testApplicationId, '', $filters);

        $this->assertGreaterThanOrEqual(2, count($results));
    }

    /**
     * Test 15: XSS prevention in message content
     */
    public function testXssPrevention()
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror="alert(1)">',
            '<svg onload="alert(1)">',
        ];

        foreach ($xssPayloads as $payload) {
            $sanitized = sanitizeRichText($payload);

            $stmt = $this->db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message, message_html)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$this->testApplicationId, $this->testUserId, $sanitized, $sanitized]);

            $messageId = $this->db->lastInsertId();

            // Verify sanitized content
            $stmt = $this->db->prepare("SELECT message_html FROM discussion_messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $result = $stmt->fetch();

            // Verify dangerous HTML tags and event handlers are stripped
            $this->assertStringNotContainsString('<script', $result['message_html']);
            $this->assertStringNotContainsString('onerror', $result['message_html']);
            $this->assertStringNotContainsString('onload', $result['message_html']);
        }

        // Test javascript: protocol is blocked in href context
        $jsSanitized = sanitizeRichText('<a href="javascript:alert(\'XSS\')">click</a>');
        $this->assertStringNotContainsString('javascript:', $jsSanitized);
    }
}
