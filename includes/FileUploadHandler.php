<?php
declare(strict_types=1);
/**
 * File Upload Handler for Discussion Attachments
 * SPEC: SPEC-DISC-001 (File Attachment Support)
 * Description: Secure file upload handler with validation and sanitization
 * Version: 1.0.0
 * Date: 2025-01-04
 */

class FileUploadHandler
{
    // Configuration constants
    const MAX_FILE_SIZE = 10485760; // 10MB in bytes
    const MAX_FILES_PER_MESSAGE = 5;

    // Allowed MIME types
    const ALLOWED_MIME_TYPES = [
        // Documents
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',

        // Images
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

    // Blocked file extensions (executables)
    const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'sh', 'bash',
        'vbs', 'js', 'jar', 'app', 'deb', 'rpm', 'dmg', 'pkg',
        'msi', 'dll', 'so', 'dylib'
    ];

    /**
     * @var bool Skip is_uploaded_file check (for testing only)
     */
    public static bool $skipUploadCheck = false;

    /**
     * Validate file upload
     *
     * @param array $file File from $_FILES array
     * @param string $error Error output parameter
     * @return array|null Validated file info or null on failure
     */
    public static function validateFile($file, &$error = '')
    {
        // Check for upload errors
        if (!isset($file['error']) || is_array($file['error'])) {
            $error = 'Invalid file upload.';
            return null;
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = 'File exceeds maximum size limit.';
                return null;
            case UPLOAD_ERR_NO_FILE:
                $error = 'No file uploaded.';
                return null;
            default:
                $error = 'Unknown upload error.';
                return null;
        }

        // Verify file was actually uploaded (skipped in test environment)
        if (!self::$skipUploadCheck && !is_uploaded_file($file['tmp_name'])) {
            $error = 'Invalid file upload.';
            return null;
        }

        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            $error = 'File size exceeds 10MB limit.';
            return null;
        }

        // Get file extension
        $filename = $file['name'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Check for blocked extensions
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            $error = 'Executable files are not allowed.';
            return null;
        }

        // Detect MIME type
        $mimeType = self::detectMimeType($file['tmp_name']);

        // Validate MIME type
        if (!array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            $error = 'File type not allowed. Allowed types: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, JPG, PNG, GIF.';
            return null;
        }

        // Verify extension matches MIME type
        $expectedExtension = self::ALLOWED_MIME_TYPES[$mimeType];
        if ($extension !== $expectedExtension && !self::isAlternativeExtension($extension, $mimeType)) {
            $error = 'File extension does not match file type.';
            return null;
        }

        return [
            'tmp_name' => $file['tmp_name'],
            'original_name' => $filename,
            'file_size' => $file['size'],
            'mime_type' => $mimeType,
            'extension' => $extension
        ];
    }

    /**
     * Save uploaded file
     *
     * @param array $validatedFile Validated file info from validateFile()
     * @param int $applicationId Application ID
     * @param int $userId User ID uploading the file
     * @param int|null $messageId Optional message ID for attachment
     * @return array|false File record or false on failure
     */
    public static function saveFile($validatedFile, $applicationId, $userId, $messageId = null)
    {
        $db = Database::getInstance()->getConnection();

        // Generate unique filename
        $storedFilename = self::generateStoredFilename($validatedFile['original_name']);

        // Create upload directory structure: uploads/YYYY/MM/DD/
        $datePath = date('Y/m/d');
        $uploadDir = dirname(__DIR__) . "/uploads/$datePath";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filepath = "$uploadDir/$storedFilename";

        // Move uploaded file
        if (!move_uploaded_file($validatedFile['tmp_name'], $filepath)) {
            error_log("Failed to move uploaded file to: $filepath");
            return false;
        }

        // Set proper permissions
        chmod($filepath, 0644);

        // Save file record to database
        $sql = "
            INSERT INTO uploaded_files
            (application_id, message_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $applicationId,
                $messageId,
                $validatedFile['original_name'],
                $storedFilename,
                "uploads/$datePath/$storedFilename",
                $validatedFile['file_size'],
                $validatedFile['mime_type'],
                $userId
            ]);

            $fileId = $db->lastInsertId();

            // Log to audit trail
            logAudit(
                'uploaded_files',
                $fileId,
                'upload',
                null,
                $validatedFile['original_name'],
                'insert'
            );

            return [
                'id' => $fileId,
                'original_filename' => $validatedFile['original_name'],
                'stored_filename' => $storedFilename,
                'file_path' => "uploads/$datePath/$storedFilename",
                'file_size' => $validatedFile['file_size'],
                'mime_type' => $validatedFile['mime_type']
            ];
        } catch (PDOException $e) {
            error_log("Database error saving file record: " . $e->getMessage());

            // Clean up uploaded file on database error
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return false;
        }
    }

    /**
     * Handle multiple file uploads for a message
     *
     * @param array $files Files from $_FILES array
     * @param int $applicationId Application ID
     * @param int $userId User ID
     * @param int|null $messageId Optional message ID
     * @return array Array of [success => [], errors => []]
     */
    public static function handleMultipleUploads($files, $applicationId, $userId, $messageId = null)
    {
        $result = [
            'success' => [],
            'errors' => []
        ];

        // Check file count limit
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        if ($fileCount > self::MAX_FILES_PER_MESSAGE) {
            $result['errors'][] = "Maximum " . self::MAX_FILES_PER_MESSAGE . " files per message.";
            return $result;
        }

        // Reorganize files array
        $fileArray = self::reorganizeFilesArray($files);

        // Process each file
        foreach ($fileArray as $index => $file) {
            $error = '';
            $validated = self::validateFile($file, $error);

            if ($validated === null) {
                $result['errors'][] = "File " . ($index + 1) . ": $error";
                continue;
            }

            $saved = self::saveFile($validated, $applicationId, $userId, $messageId);

            if ($saved === false) {
                $result['errors'][] = "File " . ($index + 1) . ": Failed to save file.";
                continue;
            }

            $result['success'][] = $saved;
        }

        return $result;
    }

    /**
     * Delete uploaded file
     *
     * @param int $fileId File ID
     * @param int $userId User ID (for authorization check)
     * @return bool True on success, false on failure
     */
    public static function deleteFile($fileId, $userId)
    {
        $db = Database::getInstance()->getConnection();

        // Get file record
        $stmt = $db->prepare("SELECT * FROM uploaded_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            return false;
        }

        // Check authorization (admin or uploader)
        if (!Auth::isAdmin() && (int) $file['uploaded_by'] !== (int) $userId) {
            return false;
        }

        // Delete physical file with path traversal protection
        $filepath = dirname(__DIR__) . '/' . $file['file_path'];
        $realPath = realpath($filepath);
        $uploadsDir = realpath(dirname(__DIR__) . '/uploads');
        if ($realPath && $uploadsDir && strpos($realPath, $uploadsDir) === 0) {
            unlink($realPath);
        } elseif ($realPath) {
            error_log("FileUploadHandler: Blocked deletion outside uploads directory: $filepath");
        }

        // Delete database record
        $stmt = $db->prepare("DELETE FROM uploaded_files WHERE id = ?");
        $stmt->execute([$fileId]);

        // Log to audit trail
        logAudit(
            'uploaded_files',
            $fileId,
            'delete',
            $file['original_filename'],
            null,
            'delete'
        );

        return true;
    }

    /**
     * Detect MIME type using fileinfo or fallback methods
     */
    private static function detectMimeType($filepath)
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $filepath);
                finfo_close($finfo);
                return $mime ?: 'application/octet-stream';
            }
        }

        if (function_exists('mime_content_type')) {
            return mime_content_type($filepath) ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    /**
     * Check if extension is alternative for MIME type
     */
    private static function isAlternativeExtension($extension, $mimeType)
    {
        $alternatives = [
            'jpg' => ['jpeg'],
            'jpeg' => ['jpg'],
            'txt' => ['text', 'log'],
        ];

        return isset($alternatives[$extension]) &&
               in_array(self::ALLOWED_MIME_TYPES[$mimeType], $alternatives[$extension], true);
    }

    /**
     * Generate secure stored filename
     */
    private static function generateStoredFilename($originalName)
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $randomPart = bin2hex(random_bytes(16));
        return $extension ? $randomPart . '.' . $extension : $randomPart;
    }

    /**
     * Reorganize $_FILES array for multiple uploads
     */
    private static function reorganizeFilesArray($files)
    {
        $result = [];

        if (!is_array($files['name'])) {
            return [$files];
        }

        $fileCount = count($files['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            $result[] = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
        }

        return $result;
    }
}
