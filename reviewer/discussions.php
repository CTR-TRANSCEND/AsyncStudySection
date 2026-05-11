<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/DiscussionHelper.php';
require_once '../includes/FileUploadHandler.php';

Auth::requireReviewer();

$db = Database::getInstance()->getConnection();
$userId = Auth::getUserId();
$error = '';
$success = '';
$csrfError = null;

// Get all applications assigned to this reviewer with message counts and unread status
$stmt = $db->prepare("
    SELECT
        a.*,
        ss.name as study_section_name,
        COALESCE(gt.name, a.grant_type) as grant_type_name,
        ass.anonymous_label,
        COUNT(DISTINCT dm.id) as total_messages,
        COALESCE(dmr.unread_count, 0) as unread_count
    FROM assignments ass
    JOIN applications a ON ass.application_id = a.id
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    LEFT JOIN discussion_messages dm ON a.id = dm.application_id AND dm.is_deleted = FALSE
    LEFT JOIN (
        SELECT dmr2.application_id, COUNT(DISTINCT dmr2.message_id) as unread_count
        FROM discussion_message_reads dmr2
        WHERE dmr2.user_id = ?
        GROUP BY dmr2.application_id
    ) dmr ON a.id = dmr.application_id
    WHERE ass.reviewer_id = ?
      AND (ss.is_active = TRUE OR ss.id IS NULL)
    GROUP BY a.id
    ORDER BY dmr.unread_count DESC, a.created_at DESC
");
$stmt->execute([$userId, $userId]);
$applications = $stmt->fetchAll();

$selectedAppId = isset($_GET['app_id']) ? (int) $_GET['app_id'] : null;
$selectedApp = null;
$messages = [];
$attachments = [];

// Handle search and filters
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$filters = [
    'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : null,
    'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : null,
    'has_attachments' => isset($_GET['has_attachments']) ? filter_var($_GET['has_attachments'], FILTER_VALIDATE_BOOLEAN) : null,
    'is_edited' => isset($_GET['is_edited']) ? filter_var($_GET['is_edited'], FILTER_VALIDATE_BOOLEAN) : null,
];

if ($selectedAppId) {
    // Verify access
    if (!hasApplicationAccess($selectedAppId, $userId)) {
        $selectedAppId = null;
        $error = 'You do not have access to this application.';
    } else {
        // Get application details
        foreach ($applications as $app) {
            if ((int)$app['id'] === $selectedAppId) {
                $selectedApp = $app;
                break;
            }
        }

        if ($selectedApp) {
            // Get messages for this application
            if (!empty($searchTerm) || !empty(array_filter($filters))) {
                // Use search with filters
                $messages = DiscussionHelper::searchMessages($db, $selectedAppId, $searchTerm, $filters);
            } else {
                // Get all messages
                $stmt = $db->prepare("
                    SELECT
                        dm.*,
                        ass.anonymous_label,
                        u.id as user_id,
                        (SELECT COUNT(*) FROM uploaded_files uf WHERE uf.message_id = dm.id) as attachment_count
                    FROM discussion_messages dm
                    JOIN users u ON dm.user_id = u.id
                    JOIN assignments ass ON dm.application_id = ass.application_id AND dm.user_id = ass.reviewer_id
                    WHERE dm.application_id = ?
                      AND dm.is_deleted = FALSE
                    ORDER BY dm.is_pinned DESC, dm.created_at ASC
                ");
                $stmt->execute([$selectedAppId]);
                $messages = $stmt->fetchAll();
            }

            // Get attachments for all messages
            $messageIds = array_column($messages, 'id');
            if (!empty($messageIds)) {
                $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
                $stmt = $db->prepare("
                    SELECT uf.*, uf.message_id
                    FROM uploaded_files uf
                    WHERE uf.message_id IN ($placeholders)
                ");
                $stmt->execute($messageIds);
                $attachments = $stmt->fetchAll();

                // Group attachments by message_id
                $attachmentsByMessage = [];
                foreach ($attachments as $att) {
                    $attachmentsByMessage[$att['message_id']][] = $att;
                }
                $attachments = $attachmentsByMessage;
            }
        }
    }
}

// Handle new message with rich text and file attachments
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

// Handle file upload for message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_attachment']) && !$csrfError) {
    $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;

    if (hasApplicationAccess($appId, $userId) && isset($_FILES['attachment'])) {
        $uploadResult = FileUploadHandler::handleMultipleUploads($_FILES['attachment'], $appId, $userId);

        if (!empty($uploadResult['success'])) {
            $success = count($uploadResult['success']) . ' file(s) uploaded successfully.';
        }

        if (!empty($uploadResult['errors'])) {
            $error = implode(' ', $uploadResult['errors']);
        }
    }
}

// Handle send message with rich text
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && !$csrfError) {
    $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
    $message = isset($_POST['message']) ? $_POST['message'] : '';

    // Check if discussion is locked
    $stmt = $db->prepare("SELECT discussion_locked, discussion_locked_reason FROM applications WHERE id = ?");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    if ($app && $app['discussion_locked']) {
        $error = 'This discussion has been locked by an administrator.';
        if (!empty($app['discussion_locked_reason'])) {
            $error .= ' Reason: ' . htmlspecialchars($app['discussion_locked_reason']);
        }
    } elseif (hasApplicationAccess($appId, $userId) && !empty($message)) {
        // Sanitize rich text content
        $sanitizedMessage = sanitizeRichText($message);
        $sanitizedHtml = sanitizeRichText($message); // Store HTML version

        if (!empty($sanitizedMessage)) {
            $stmt = $db->prepare("
                INSERT INTO discussion_messages (application_id, user_id, message, message_html)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$appId, $userId, $sanitizedMessage, $sanitizedHtml]);

            // Link any uploaded files to this message
            if (!empty($_POST['uploaded_file_ids'])) {
                $fileIds = explode(',', $_POST['uploaded_file_ids']);
                foreach ($fileIds as $fileId) {
                    $fileId = trim($fileId);
                    if (is_numeric($fileId)) {
                        $stmt = $db->prepare("
                            UPDATE uploaded_files
                            SET message_id = LAST_INSERT_ID()
                            WHERE id = ? AND uploaded_by = ? AND message_id IS NULL
                        ");
                        $stmt->execute([$fileId, $userId]);
                    }
                }
            }

            header('Location: discussions.php?app_id=' . $appId . '#messages');
            exit;
        }
    } elseif (empty($message)) {
        $error = 'Message cannot be empty.';
    }
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read']) && !$csrfError) {
    $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;

    if (hasApplicationAccess($appId, $userId)) {
        $markedCount = DiscussionHelper::markAllAsRead($db, $userId, $appId);
        $success = "Marked $markedCount messages as read.";
    }
}

$pageTitle = 'Discussions';
require_once '../includes/header.php';
?>

<h1 class="mb-4">Discussions</h1>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo escape($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo escape($success); ?></div>
<?php endif; ?>

<div class="grid grid-3 mb-4">
    <div class="stat-card">
        <div class="stat-label">Assigned Applications</div>
        <div class="stat-value"><?php echo count($applications); ?></div>
    </div>
    <div class="stat-card" style="border-left-color: var(--danger-color);">
        <div class="stat-label">Unread Messages</div>
        <div class="stat-value"><?php echo array_sum(array_column($applications, 'unread_count')); ?></div>
    </div>
    <div class="stat-card" style="border-left-color: var(--success-color);">
        <div class="stat-label">Total Messages</div>
        <div class="stat-value"><?php echo array_sum(array_column($applications, 'total_messages')); ?></div>
    </div>
</div>

<div class="grid grid-3">
    <!-- Applications List -->
    <div>
        <div class="card">
            <div class="card-header">Applications</div>
            <div class="app-list">
                <?php if (empty($applications)): ?>
                    <div class="p-3 text-muted text-center">
                        No applications assigned yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($applications as $app): ?>
                        <a href="discussions.php?app_id=<?php echo (int) $app['id']; ?>"
                           class="app-list-item <?php echo ($selectedAppId === (int)$app['id']) ? 'active' : ''; ?>"
                           style="text-decoration: none; color: inherit; display: block;">
                            <div style="flex: 1;">
                                <div><strong><?php echo escape($app['grant_id'] ?? $app['applicant_name']); ?></strong></div>
                                <?php if (!empty($app['study_section_name'])): ?>
                                    <div class="message-count"><?php echo escape($app['study_section_name']); ?></div>
                                <?php endif; ?>
                                <div class="message-count">
                                    <?php echo (int) $app['total_messages']; ?> message<?php echo (int)$app['total_messages'] !== 1 ? 's' : ''; ?>
                                </div>
                            </div>
                            <?php if ($app['unread_count'] > 0): ?>
                                <div class="unread-badge"><?php echo (int) $app['unread_count']; ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Discussion Area -->
    <div style="grid-column: span 2;">
        <?php if (!$selectedApp): ?>
            <div class="card">
                <div class="card-body text-center" style="padding: 3rem;">
                    <p class="text-muted">Select an application from the left to view discussions.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header">
                    Discussion: <?php echo escape($selectedApp['grant_id'] ?? $selectedApp['applicant_name']); ?>
                    <span class="badge badge-secondary" style="float: right;">
                        Your Role: <?php echo escape($selectedApp['anonymous_label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div style="margin-bottom: 1rem;">
                        <strong>Title:</strong> <?php echo escape(substr($selectedApp['application_title'], 0, 100)) . (strlen($selectedApp['application_title']) > 100 ? '...' : ''); ?>
                    </div>

                    <!-- Search Panel -->
                    <div class="search-panel">
                        <form method="GET" class="mb-3">
                            <input type="hidden" name="app_id" value="<?php echo (int) $selectedAppId; ?>">
                            <div class="d-flex gap-2">
                                <input type="text" name="search" class="form-control" placeholder="Search messages..."
                                       value="<?php echo escape($searchTerm); ?>">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <?php if ($searchTerm || !empty(array_filter($filters))): ?>
                                    <a href="discussions.php?app_id=<?php echo (int) $selectedAppId; ?>" class="btn btn-secondary">Clear</a>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 d-flex gap-2">
                                <label><input type="checkbox" name="has_attachments" value="1"
                                    <?php echo isset($filters['has_attachments']) && $filters['has_attachments'] ? 'checked' : ''; ?>>
                                    Has attachments</label>
                                <label><input type="checkbox" name="is_edited" value="1"
                                    <?php echo isset($filters['is_edited']) && $filters['is_edited'] ? 'checked' : ''; ?>>
                                    Edited only</label>
                            </div>
                        </form>
                    </div>

                    <!-- Messages -->
                    <div class="chat-container" id="messages">
                        <?php if (empty($messages)): ?>
                            <p class="text-muted">No messages yet. Start the discussion!</p>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <?php $isMyMessage = ((int)$msg['user_id'] === (int)$userId); ?>
                                <div class="chat-message <?php echo $isMyMessage ? 'my-message' : ''; ?> <?php echo $msg['is_pinned'] ? 'pinned' : ''; ?>">
                                    <div class="chat-message-header">
                                        <span class="chat-author">
                                            <?php echo escape($msg['anonymous_label']); ?>
                                            <?php if ($isMyMessage): ?>
                                                <span class="badge badge-success" style="font-size: 0.7rem;">You</span>
                                            <?php endif; ?>
                                            <?php if ($msg['is_pinned']): ?>
                                                <span class="pinned-indicator">📌 Pinned</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="chat-time"><?php echo formatDateTime($msg['created_at']); ?></span>
                                    </div>
                                    <div class="chat-content">
                                        <?php if (!empty($msg['message_html'])): ?>
                                            <?php echo $msg['message_html']; ?>
                                        <?php else: ?>
                                            <?php echo nl2br(escape($msg['message'])); ?>
                                        <?php endif; ?>
                                        <?php if ($msg['is_edited']): ?>
                                            <span class="text-muted" style="font-size: 0.75rem;">(edited)</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (isset($attachments[$msg['id']]) && !empty($attachments[$msg['id']])): ?>
                                        <div class="attachment-list">
                                            <?php foreach ($attachments[$msg['id']] as $att): ?>
                                                <div class="attachment-item">
                                                    <span class="attachment-icon">📎</span>
                                                    <a href="../<?php echo escape($att['file_path']); ?>"
                                                       download="<?php echo escape($att['original_filename']); ?>">
                                                        <?php echo escape($att['original_filename']); ?>
                                                    </a>
                                                    <span class="text-muted" style="font-size: 0.75rem;">
                                                        (<?php echo number_format($att['file_size'] / 1024, 1); ?> KB)
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Message Input with File Upload -->
                    <form method="POST" class="chat-input-area" enctype="multipart/form-data">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="application_id" value="<?php echo $selectedAppId; ?>">

                        <!-- File Upload -->
                        <div class="mb-2">
                            <label>Attach files (max 5, 10MB each):</label>
                            <input type="file" name="attachment[]" class="form-control" multiple
                                   accept=".pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                            <button type="submit" name="upload_attachment" class="btn btn-secondary btn-sm mt-1">
                                Upload Files
                            </button>
                        </div>

                        <!-- Message Input -->
                        <textarea
                            name="message"
                            class="form-control chat-input"
                            placeholder="Type your message... (Supports basic formatting: *bold*, /italic/, _underline_)"
                            rows="4"
                            required
                        ></textarea>

                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                            <button type="submit" name="mark_all_read" class="btn btn-secondary">Mark All as Read</button>
                        </div>

                        <small class="text-muted">
                            Supported: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, JPG, PNG, GIF (max 10MB each)
                        </small>
                    </form>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="mt-3 d-flex gap-2">
                <a href="view_all_reviews.php?id=<?php echo $selectedAppId; ?>" class="btn btn-secondary btn-sm">
                    📊 View All Reviews
                </a>
                <a href="review_application.php?id=<?php echo $selectedAppId; ?>" class="btn btn-primary btn-sm">
                    ✏️ My Review
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-scroll to bottom of messages
window.addEventListener('load', function() {
    const chatContainer = document.querySelector('.chat-container');
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
});

// Auto-refresh every 30 seconds if on discussions page
<?php if ($selectedAppId): ?>
setInterval(function() {
    // Silent refresh - could use AJAX to only refresh messages
    window.location.reload();
}, 30000);
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
