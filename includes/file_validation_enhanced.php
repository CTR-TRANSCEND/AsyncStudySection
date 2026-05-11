<?php
/**
 * Enhanced File Upload Validation Implementation
 *
 * SPEC-SEC-001: File Upload Security Enhancements
 *
 * Features:
 * - Magic number validation before extension check
 * - ZIP structure validation for .docx files
 * - Malicious content scanning (macros, scripts, executables)
 * - File quarantine system
 * - Comprehensive logging
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Enhanced DOCX upload validation with content checking
 *
 * Performs multi-layer validation:
 * 1. Basic validation (extension, size)
 * 2. Magic number validation
 * 3. ZIP structure validation
 * 4. Malicious content scanning
 *
 * @param array $file The uploaded file from $_FILES
 * @param string|null $error Error output parameter
 * @return array|null Normalized file info or null if invalid
 */
function validateDocxUploadEnhanced(array $file, ?string &$error = null): ?array
{
    // Basic validation
    $basicValidation = validateDocxUpload($file, $error);
    if ($basicValidation === null) {
        return null;
    }

    $tmpPath = $file['tmp_name'];

    // Magic number validation
    if (!validateDocxMagicNumber($tmpPath)) {
        $error = 'File content does not match .docx format.';
        quarantineFile($file);
        return null;
    }

    // ZIP structure validation
    if (!validateDocxStructure($tmpPath)) {
        $error = 'Invalid .docx file structure.';
        quarantineFile($file);
        return null;
    }

    // Content scanning for malicious patterns
    if (scanForMaliciousContent($tmpPath)) {
        $error = 'File contains potentially malicious content.';
        quarantineFile($file);
        return null;
    }

    return $basicValidation;
}

/**
 * Validate DOCX magic number
 *
 * .docx files are ZIP archives starting with "PK\x03\x04".
 * Checks the file header to verify actual format.
 *
 * @param string $filePath Path to the uploaded file
 * @return bool True if magic number is valid
 */
function validateDocxMagicNumber(string $filePath): bool
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }

    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return false;
    }

    $magicNumber = fread($handle, 4);
    fclose($handle);

    // .docx files are ZIP archives (magic number: PK\x03\x04)
    return $magicNumber === "PK\x03\x04";
}

/**
 * Validate DOCX ZIP structure
 *
 * Verifies that the file is a valid ZIP archive containing
 * required .docx components.
 *
 * @param string $filePath Path to the uploaded file
 * @return bool True if structure is valid
 */
function validateDocxStructure(string $filePath): bool
{
    if (!extension_loaded('zip')) {
        // Fallback: if zip extension not available, just check magic number
        return true;
    }

    $zip = new ZipArchive();
    $status = $zip->open($filePath);

    if ($status !== true) {
        return false;
    }

    // Validate required .docx structure
    $requiredFiles = [
        '[Content_Types].xml',
        '_rels/.rels',
        'word/document.xml'
    ];

    foreach ($requiredFiles as $requiredFile) {
        if ($zip->locateName($requiredFile) === false) {
            $zip->close();
            return false;
        }
    }

    $zip->close();
    return true;
}

/**
 * Scan for malicious content in DOCX file
 *
 * Searches for suspicious patterns including:
 * - Macros (<macro>, <vbaProject>)
 * - OLE objects (<o:>)
 * - Executable file references
 * - Script injection
 *
 * @param string $filePath Path to the uploaded file
 * @return bool True if malicious content is detected
 */
function scanForMaliciousContent(string $filePath): bool
{
    if (!extension_loaded('zip')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return false;
    }

    // Suspicious patterns to detect
    $suspiciousPatterns = [
        '/<macro.*?>/i',
        '/<vbaProject.*?>/i',
        '/<o:.*?>/i',
        '/\.exe$/i',
        '/\.dll$/i',
        '/\.bat$/i',
        '/\.sh$/i',
        '/\.cmd$/i',
        '/\.ps1$/i',
        '/<script.*?java/i',
        '/javascript:/i',
        '/eval\s*\(/i',
        '/ActiveXObject/i'
    ];

    // Scan files in ZIP
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            continue;
        }

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $zip->close();
                logSecurityEvent('malicious_content_detected', [
                    'file' => $filePath,
                    'pattern' => $pattern,
                    'zip_index' => $i
                ]);
                return true;
            }
        }
    }

    $zip->close();
    return false;
}

/**
 * Quarantine suspicious file
 *
 * Moves file to quarantine directory and notifies administrators.
 *
 * @param array $file The uploaded file to quarantine
 * @return bool True if quarantine succeeded
 */
function quarantineFile(array $file): bool
{
    $quarantineDir = UPLOAD_DIR . 'quarantine/';

    if (!is_dir($quarantineDir)) {
        if (!mkdir($quarantineDir, 0755, true)) {
            error_log('Failed to create quarantine directory');
            return false;
        }
    }

    $storedFilename = generateStoredFilename($file['name']);
    $destination = $quarantineDir . $storedFilename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        logSecurityEvent('file_quarantined', [
            'original_name' => $file['name'],
            'stored_name' => $storedFilename,
            'size' => $file['size']
        ]);

        // Notify administrators
        notifyAdminsOfFileQuarantine($file['name']);

        return true;
    }

    return false;
}

/**
 * Notify administrators of quarantined file
 *
 * @param string $filename The original filename
 * @return void
 */
function notifyAdminsOfFileQuarantine(string $filename): void
{
    $db = Database::getInstance()->getConnection();

    try {
        $stmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND is_active = TRUE");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $email) {
            $subject = "[Security] Quarantined File Alert";
            $message = "A suspicious file was quarantined:\n\n";
            $message .= "Filename: $filename\n";
            $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $message .= "Please review the quarantine directory.";

            mail($email, $subject, $message);
        }
    } catch (PDOException $e) {
        error_log('Failed to notify admins of quarantined file: ' . $e->getMessage());
    }
}

/**
 * Validate file path to prevent directory traversal
 *
 * @param string $filename The filename to validate
 * @return bool True if path is safe
 */
function isValidFilename(string $filename): bool
{
    // Check for directory traversal
    if (strpos($filename, '../') !== false || strpos($filename, '..\\') !== false) {
        return false;
    }

    // Check for absolute paths
    if (DIRECTORY_SEPARATOR === '/') {
        if (strpos($filename, '/') === 0) {
            return false;
        }
    } else {
        if (preg_match('/^[a-zA-Z]:\\\\/', $filename)) {
            return false;
        }
    }

    // Check for null bytes
    if (strpos($filename, "\0") !== false) {
        return false;
    }

    // Check for suspicious characters
    if (preg_match('/[<>:"|?*]/', $filename)) {
        return false;
    }

    return true;
}

/**
 * Get file information safely
 *
 * @param string $filePath Path to the file
 * @return array|false File info or false on error
 */
function getSafeFileInfo(string $filePath)
{
    if (!file_exists($filePath) || !is_file($filePath)) {
        return false;
    }

    $info = [
        'size' => filesize($filePath),
        'modified' => filemtime($filePath),
        'readable' => is_readable($filePath),
        'writable' => is_writable($filePath)
    ];

    // Check if file is outside web root
    $realPath = realpath($filePath);
    $webRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));

    if ($realPath && $webRoot) {
        $info['outside_web_root'] = strpos($realPath, $webRoot) !== 0;
    }

    return $info;
}

/**
 * Generate cryptographically secure random filename
 *
 * @param string $originalName The original filename
 * @return string The generated filename
 */
function generateSecureFilename(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $randomBytes = bin2hex(random_bytes(16));

    return $extension ? $randomBytes . '.' . $extension : $randomBytes;
}
