<?php
declare(strict_types=1);
// File upload utility functions extracted from functions.php

/**
 * Generate secure random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate safe stored filename based on original extension
 */
function generateStoredFilename($originalName) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $randomName = bin2hex(random_bytes(16));
    return $extension ? $randomName . '.' . $extension : $randomName;
}

/**
 * Detect MIME type for a file
 */
function detectMimeType($filePath) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            return $mime ?: '';
        }
    }

    if (function_exists('mime_content_type')) {
        return mime_content_type($filePath) ?: '';
    }

    return '';
}

/**
 * Validate a docx upload and return normalized info
 */
function validateDocxUpload(array $file, &$error) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload error.';
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid file upload.';
        return null;
    }

    $filename = $file['name'] ?? '';
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'] ?? 0;
    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($fileExt !== 'docx') {
        $error = 'Only .docx files are allowed.';
        return null;
    }

    if ($fileSize > MAX_UPLOAD_SIZE) {
        $error = 'File size exceeds maximum allowed size.';
        return null;
    }

    $mime = detectMimeType($tmpPath);
    $allowedMimes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream'
    ];

    if ($mime && !in_array($mime, $allowedMimes, true)) {
        $error = 'Invalid file type detected.';
        return null;
    }

    return [
        'original_name' => $filename,
        'tmp_path' => $tmpPath,
        'file_size' => $fileSize,
        'mime_type' => $mime ?: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
}
