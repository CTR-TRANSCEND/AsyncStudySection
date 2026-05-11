<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$applicationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Get application details
$stmt = $db->prepare("
    SELECT a.*, ss.name as study_section_name, COALESCE(gt.name, a.grant_type) as grant_type_name
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    WHERE a.id = ?
");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: applications.php');
    exit;
}

// Get discussion messages
$stmt = $db->prepare("
    SELECT dm.*, u.full_name, a.anonymous_label
    FROM discussion_messages dm
    JOIN users u ON dm.user_id = u.id
    LEFT JOIN assignments a ON dm.application_id = a.application_id AND dm.user_id = a.reviewer_id
    WHERE dm.application_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$applicationId]);
$messages = $stmt->fetchAll();

$pageTitle = 'Discussion - Admin View';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <a href="applications.php" class="btn btn-secondary btn-sm">← Back to Applications</a>
    <a href="application_detail.php?id=<?php echo $applicationId; ?>" class="btn btn-primary btn-sm">View Application Details</a>
</div>

<h1 class="mb-4">Discussion Board - Admin View</h1>

<!-- Application Info -->
<div class="card mb-4">
    <div class="card-header">Application Information</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <p><strong>Grant ID:</strong> <?php echo escape($application['grant_id'] ?? 'N/A'); ?></p>
                <p><strong>Applicant:</strong> <?php echo escape($application['applicant_name']); ?></p>
            </div>
            <div>
                <p><strong>Study Section:</strong> <?php echo escape($application['study_section_name'] ?? 'N/A'); ?></p>
                <p><strong>Grant Type:</strong> <span class="badge badge-primary"><?php echo escape($application['grant_type_name'] ?? $application['grant_type']); ?></span></p>
                <p><strong>Total Messages:</strong> <?php echo count($messages); ?></p>
            </div>
        </div>
        <p><strong>Title:</strong> <?php echo escape($application['application_title']); ?></p>
    </div>
</div>

<!-- Discussion Messages -->
<div class="card mb-4">
    <div class="card-header">All Discussion Messages</div>
    <div class="card-body">
        <div class="chat-container">
            <?php if (empty($messages)): ?>
                <p class="text-muted">No discussion messages yet.</p>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="chat-message">
                        <div class="chat-message-header">
                            <span class="chat-author">
                                <strong><?php echo escape($msg['full_name']); ?></strong>
                                <?php if ($msg['anonymous_label']): ?>
                                    <span class="badge badge-secondary" style="font-size: 0.75rem;">
                                        <?php echo escape($msg['anonymous_label']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger" style="font-size: 0.75rem;">Admin</span>
                                <?php endif; ?>
                            </span>
                            <span class="chat-time"><?php echo formatDateTime($msg['created_at']); ?></span>
                        </div>
                        <div class="chat-content">
                            <?php echo nl2br(escape($msg['message'])); ?>
                            <?php if ($msg['is_edited']): ?>
                                <span class="text-muted" style="font-size: 0.75rem;">(edited)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-scroll to bottom
window.addEventListener('load', function() {
    const chatContainer = document.querySelector('.chat-container');
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
