<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../includes/auth.php';

Auth::requireAdmin();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="user_import_template.csv"');
header('Cache-Control: max-age=0');

// Create template with headers and sample data.
// 8-column format per SPEC-NAME-SPLIT-001. The legacy 6-column format
// (full_name in place of first_name/last_name/degrees) is still accepted
// by the importer with a deprecation warning, but the template now
// generates the new layout.
$template = [
    ['username',   'password',  'first_name', 'last_name', 'degrees', 'email',                   'institution',           'role'],
    ['reviewer11', 'CHANGE_ME', 'John',       'Doe',       'PhD',     'john.doe@example.com',    'University of Example', 'reviewer'],
    ['reviewer12', 'CHANGE_ME', 'Jane',       'Smith',     '',        'jane.smith@example.com',  'Example Institute',     'reviewer'],
];

// Output CSV
$output = fopen('php://output', 'w');
foreach ($template as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
