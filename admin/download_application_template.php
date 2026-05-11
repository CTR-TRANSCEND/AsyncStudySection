<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../includes/auth.php';

Auth::requireAdmin();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="application_import_template.csv"');
header('Cache-Control: max-age=0');

// Create template with headers and sample data
$template = [
    ['grant_id', 'applicant_name', 'application_title', 'grant_type', 'study_section'],
    ['GRANT-2024-001', 'Dr. John Doe', 'Novel approach to cancer treatment', 'TRANSCEND Pilot', 'TRANSCEND 2025'],
    ['GRANT-2024-002', 'Dr. Jane Smith', 'Development of new diagnostic tool', 'TRANSCEND Developmental', 'TRANSCEND 2025'],
];

// Output CSV
$output = fopen('php://output', 'w');
foreach ($template as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
