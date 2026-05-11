# Asynchronous Grant Review System - Project Summary

## Overview

### What the Project Does
The **Asynchronous Grant Review System** is a web-based application for managing peer review of grant applications. It enables:
- **Admins** to upload, parse, and manage grant applications and review reports
- **Reviewers** to submit and edit their reviews with anonymity preserved
- **Discussion forums** for reviewers to communicate about applications
- **Automated parsing** of Word document (.docx) review reports into structured data
- **Statistical analysis** of reviews with score aggregation and reporting

### Main Components

1. **Authentication & Authorization**
   - Role-based access control (Admin, Reviewer)
   - Session-based authentication with bcrypt password hashing
   - Anonymous labeling system (Reviewer A, B, C) for peer confidentiality

2. **Application Management**
   - CRUD operations for grant applications
   - Support for two grant types: Pilot and Developmental
   - Bulk import from Excel/CSV
   - Application completion status to lock editing

3. **Review System**
   - Structured review forms with 1-9 scoring scale
   - Five review criteria sections (customizable)
   - Overall Impact and Relevance to RFA assessments
   - Budget evaluation for Developmental grants
   - Change tracking and audit logging

4. **Document Parser**
   - Extracts structured review data from .docx files
   - Parses scores, explanations, strengths, weaknesses
   - Validates data integrity before import
   - Supports bullet points and formatted text

5. **Discussion System**
   - Application-specific discussion threads
   - Unread message tracking
   - Real-time notifications
   - Anonymous messaging for reviewers

6. **Reporting & Analytics**
   - Statistical aggregation (mean, min, max scores)
   - Side-by-side review comparison
   - PDF report generation capability
   - Export functionality

### Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│                         ADMIN WORKFLOW                       │
└─────────────────────────────────────────────────────────────┘
         │
         ├─> Upload .docx Review Report
         │         │
         │         ├─> DocumentParser extracts data
         │         ├─> Validation & Preview
         │         ├─> Select Application & Reviewer
         │         └─> Store in Database (reviews table)
         │
         ├─> Manage Applications
         │         │
         │         ├─> Bulk import from CSV
         │         ├─> Manual entry via table
         │         └─> Assign reviewers (assignments table)
         │
         └─> View Reports & Analytics
                   │
                   └─> Generate statistics & final reports

┌─────────────────────────────────────────────────────────────┐
│                      REVIEWER WORKFLOW                       │
└─────────────────────────────────────────────────────────────┘
         │
         ├─> View Assigned Applications (dashboard)
         │
         ├─> Submit/Edit Review
         │         │
         │         ├─> Fill review form with scores & comments
         │         ├─> System validates scores (1-9)
         │         ├─> Save to reviews & review_criteria_scores
         │         └─> Audit log tracks all changes
         │
         ├─> View All Reviews (side-by-side comparison)
         │         │
         │         └─> See anonymized reviews from peers
         │
         └─> Participate in Discussions
                   │
                   ├─> Read messages from other reviewers
                   ├─> Post anonymous messages
                   └─> Unread tracking based on timestamps

┌─────────────────────────────────────────────────────────────┐
│                       DATABASE LAYER                         │
└─────────────────────────────────────────────────────────────┘
         │
         ├─> users (admins & reviewers)
         ├─> applications (grant proposals)
         ├─> assignments (reviewer-application mapping)
         ├─> reviews (main review data)
         ├─> review_criteria_scores (detailed scores)
         ├─> discussion_messages (forum threads)
         ├─> audit_log (change tracking)
         └─> uploaded_files (document tracking)
```

---

## Key Files and Roles

### Directory Structure

```
/data/var/www/html/transcend_grant_review_2.0/
├── admin/              Admin interface pages
├── reviewer/           Reviewer interface pages
├── config/             Configuration & database connection
├── includes/           Shared utilities & authentication
├── assets/             CSS, JavaScript, images
├── database/           SQL schema & migrations
├── uploads/            Uploaded review documents
└── sampleReports/      Example review documents
```

### `/admin/` - Admin Interface
Administrative pages for managing the entire system.

- **`dashboard.php`** - Admin home page with system overview, statistics on applications, reviewers, and reviews. Shows pending actions and recent activity.

- **`users.php`** - User management interface. Create, edit, delete users. Bulk import from CSV. Toggle active status and reset passwords.

- **`applications.php`** - View all applications in table format. Mark applications as complete (locks reviewer edits). Quick access to details and discussions.

- **`manage_applications.php`** - Bulk application management with inline editing. Add/edit/delete applications in spreadsheet-like table. Bulk import from CSV. Direct report upload via "Rpt" button.

- **`application_detail.php`** - Detailed view of single application. Shows all reviews, statistics, assigned reviewers, and discussion. Admin can assign/remove reviewers.

- **`upload_review.php`** - Upload and parse .docx review documents. Preview parsed data before import. Assign to application and reviewer. Supports prefilled values from manage_applications.

- **`upload.php`** - Legacy upload interface (may be deprecated in favor of upload_review.php).

- **`generate_report.php`** - Generate final consolidated report for an application. Aggregates all reviews, statistics, and discussions into PDF/document format.

- **`admin_discussion.php`** - Admin view of discussion threads. Read-only access to see all messages with real names and anonymous labels. Monitoring tool for admin oversight.

- **`download_user_template.php`** - Generates CSV template for bulk user import with sample data.

- **`download_application_template.php`** - Generates CSV template for bulk application import with sample data.

### `/reviewer/` - Reviewer Interface
Pages accessible only to reviewers for their assigned applications.

- **`dashboard.php`** - Reviewer home page. Shows assigned applications, review status (completed/pending), unread message count, and quick action buttons.

- **`applications.php`** - List of reviewer's assigned applications with filtering and sorting options.

- **`review_application.php`** - Main review submission form. Score and comment on Overall Impact, Relevance, five criteria sections, and budget. Prevents edits if application is marked complete.

- **`view_all_reviews.php`** - Side-by-side comparison of all reviews for an application. Shows statistics (mean, min, max) and highlights reviewer's own review. Read-only view of peer reviews.

- **`view_review_detail.php`** - Full detail view of a single review. Shows all scores, explanations, strengths, weaknesses, and budget comments. Print-friendly layout.

- **`discussions.php`** - Application discussion interface. Left sidebar lists assigned applications with unread badges. Right panel shows selected discussion thread. Auto-refresh every 30 seconds.

### `/config/` - Configuration
Core configuration and database setup.

- **`config.php`** - Application constants: database credentials, file paths, upload limits, scoring range (1-9), review criteria names, password requirements. Defines BASE_URL and APP_NAME.

- **`database.php`** - Database singleton class using PDO. Provides connection pooling and error handling. Example: `Database::getInstance()->getConnection()`.

### `/includes/` - Shared Utilities
Reusable components used across admin and reviewer interfaces.

- **`auth.php`** - Authentication class with static methods: `requireAdmin()`, `requireReviewer()`, `isLoggedIn()`, `getUserId()`, `getFullName()`, `login()`, `logout()`. Manages session security.

- **`functions.php`** - Utility functions:
  - `escape($str)` - XSS prevention via htmlspecialchars
  - `sanitize($str)` - Input cleaning
  - `formatDate($date)`, `formatDateTime($datetime)` - Date formatting
  - `getScoreLabel($score)` - Maps 1-9 to descriptive labels (Exceptional to Poor)
  - `getScoreColorClass($score)` - Returns CSS class for score styling
  - `getReviewStats($appId)` - Calculates mean, min, max for all review scores
  - `hasApplicationAccess($appId, $userId)` - Checks if reviewer assigned to application
  - `getAnonymousLabel($appId, $userId)` - Returns "Reviewer A", "Reviewer B", etc.
  - `logAudit($table, $recordId, $field, $oldVal, $newVal, $action)` - Audit trail logging

- **`header.php`** - HTML header and navigation menu. Role-based navigation (admin vs reviewer). Displays unread message count badge for reviewers. User avatar and logout button.

- **`footer.php`** - HTML footer with copyright and system version.

- **`DocumentParser.php`** - Parses .docx review documents into structured arrays. Extracts:
  - Applicant name and title
  - Grant type (Pilot/Developmental)
  - Overall Impact score and explanation
  - Relevance score and explanation
  - Five criteria sections (scores, strengths, weaknesses)
  - Budget information (for Developmental grants)
  - Validates scores are 1-9
  - Handles bullet points and formatting

### `/database/` - Schema & Scripts
Database structure and initialization.

- **`schema.sql`** - Complete database schema with 15 tables:
  - `users` - Admins and reviewers (username, password_hash, full_name, email, institution, role, is_active)
  - `applications` - Grant proposals (grant_id, applicant_name, application_title, grant_type, status, is_complete)
  - `assignments` - Maps reviewers to applications (reviewer_id, application_id, anonymous_label)
  - `reviews` - Main review data (overall_impact, relevance, budget, reviewer_id, application_id)
  - `review_criteria_scores` - Detailed scores for 5 criteria (review_id, criterion_name, score, strengths, weaknesses)
  - `discussion_messages` - Forum posts (application_id, user_id, message, created_at)
  - `audit_log` - Change tracking (table_name, record_id, field_name, old_value, new_value, changed_by, action_type)
  - `uploaded_files` - Document metadata (original_filename, stored_filename, file_path, uploaded_by)
  - Includes indexes, foreign keys, and default admin user

### `/assets/` - Static Resources
CSS, JavaScript, and images for UI.

- **`css/style.css`** - Main stylesheet with:
  - CSS custom properties for theming (colors, spacing)
  - Responsive grid system (grid-2, grid-3, grid-4)
  - Card components with headers
  - Form controls and buttons
  - Table styling
  - Badge components (primary, success, danger, warning)
  - Score display styling (color-coded by score)
  - Chat message styling
  - Print-friendly styles

### Root Files

- **`index.php`** - Landing page with login form. Redirects authenticated users to appropriate dashboard (admin or reviewer).

- **`login.php`** - Handles authentication POST requests. Validates credentials, creates session, logs last_login timestamp.

- **`logout.php`** - Destroys session and redirects to index.

---

## Core Classes and Functions

### Authentication (`/includes/auth.php`)

```php
class Auth {
    /**
     * Require admin role or redirect to index
     * Used at top of all admin pages
     */
    public static function requireAdmin(): void

    /**
     * Require reviewer role or redirect to index
     * Used at top of all reviewer pages
     */
    public static function requireReviewer(): void

    /**
     * Check if user is currently logged in
     * @return bool True if session exists and valid
     */
    public static function isLoggedIn(): bool

    /**
     * Get current user's database ID
     * @return int User ID from session
     */
    public static function getUserId(): int

    /**
     * Get current user's full name
     * @return string Full name for display
     */
    public static function getFullName(): string

    /**
     * Get current user's role
     * @return string 'admin' or 'reviewer'
     */
    public static function getRole(): string

    /**
     * Check if current user is admin
     * @return bool True if role === 'admin'
     */
    public static function isAdmin(): bool

    /**
     * Check if current user is reviewer
     * @return bool True if role === 'reviewer'
     */
    public static function isReviewer(): bool

    /**
     * Authenticate user and create session
     * @param string $username Username to authenticate
     * @param string $password Plain text password
     * @return bool True if login successful
     */
    public static function login(string $username, string $password): bool

    /**
     * Destroy session and log out user
     * @return void
     */
    public static function logout(): void
}
```

### Database Connection (`/config/database.php`)

```php
class Database {
    /**
     * Get singleton database instance
     * Implements connection pooling
     * @return Database Singleton instance
     */
    public static function getInstance(): Database

    /**
     * Get PDO connection object
     * @return PDO Database connection
     */
    public function getConnection(): PDO
}
```

### Utility Functions (`/includes/functions.php`)

```php
/**
 * Escape output for HTML display (XSS prevention)
 * @param string $string Raw input string
 * @return string HTML-safe string
 */
function escape(string $string): string

/**
 * Sanitize input by removing excess whitespace
 * @param string $string Raw input
 * @return string Cleaned string
 */
function sanitize(string $string): string

/**
 * Format date for display (Y-m-d -> readable)
 * @param string $date MySQL date string
 * @return string Formatted date
 */
function formatDate(string $date): string

/**
 * Format datetime for display (Y-m-d H:i:s -> readable)
 * @param string $datetime MySQL datetime string
 * @return string Formatted datetime
 */
function formatDateTime(string $datetime): string

/**
 * Get descriptive label for score (1-9)
 * 1=Exceptional, 2=Outstanding, 3=Excellent, 4=Very Good,
 * 5=Good, 6=Satisfactory, 7=Fair, 8=Marginal, 9=Poor
 * @param int $score Score from 1-9
 * @return string Descriptive label
 */
function getScoreLabel(int $score): string

/**
 * Get CSS class for score color coding
 * @param int $score Score from 1-9
 * @return string CSS class name (score-excellent, score-good, etc.)
 */
function getScoreColorClass(int $score): string

/**
 * Validate if score is in valid range (1-9)
 * @param int $score Score to validate
 * @return bool True if 1 <= score <= 9
 */
function isValidScore(int $score): bool

/**
 * Calculate review statistics for an application
 * Returns mean, min, max, and count for each review criterion
 * @param int $applicationId Application ID
 * @return array Associative array with stats per criterion
 */
function getReviewStats(int $applicationId): array

/**
 * Check if reviewer has access to application
 * @param int $applicationId Application ID
 * @param int $reviewerId Reviewer user ID
 * @return bool True if reviewer assigned to application
 */
function hasApplicationAccess(int $applicationId, int $reviewerId): bool

/**
 * Get reviewer's anonymous label for application
 * @param int $applicationId Application ID
 * @param int $reviewerId Reviewer user ID
 * @return string Anonymous label like "Reviewer A"
 */
function getAnonymousLabel(int $applicationId, int $reviewerId): string

/**
 * Log audit trail entry
 * @param string $tableName Database table affected
 * @param int $recordId Record ID in that table
 * @param string $fieldName Field that changed
 * @param mixed $oldValue Previous value
 * @param mixed $newValue New value
 * @param string $actionType 'insert', 'update', or 'delete'
 * @return void
 */
function logAudit(string $tableName, int $recordId, string $fieldName,
                  $oldValue, $newValue, string $actionType): void
```

### Document Parser (`/includes/DocumentParser.php`)

```php
class DocumentParser {
    /**
     * Parse .docx review document into structured array
     * @param string $filePath Path to .docx file
     * @return array|null Parsed data or null on failure
     * Structure: [
     *   'applicant_name' => string,
     *   'application_title' => string,
     *   'grant_type' => 'Pilot'|'Developmental',
     *   'overall_impact' => ['score' => int, 'explanation' => string],
     *   'relevance' => ['score' => int, 'explanation' => string],
     *   'criteria' => [
     *     ['name' => string, 'score' => int,
     *      'strengths' => string, 'weaknesses' => string],
     *     ...
     *   ],
     *   'budget' => ['acceptable' => bool, 'modifications' => string]
     * ]
     */
    public function parseFile(string $filePath): ?array

    /**
     * Validate parsed data structure
     * @param array $data Parsed data array
     * @return array List of validation error messages
     */
    public function validateData(array $data): array

    /**
     * Get parser error messages
     * @return array List of error messages from last parse
     */
    public function getErrors(): array

    /**
     * Extract text content from .docx file
     * @param string $filePath Path to .docx file
     * @return string Extracted text content
     */
    private function extractText(string $filePath): string

    /**
     * Parse overall impact section from text
     * @param string $text Full document text
     * @return array ['score' => int, 'explanation' => string]
     */
    private function parseOverallImpact(string $text): array

    /**
     * Parse relevance to RFA section from text
     * @param string $text Full document text
     * @return array ['score' => int, 'explanation' => string]
     */
    private function parseRelevance(string $text): array

    /**
     * Parse review criteria sections (5 sections)
     * @param string $text Full document text
     * @return array Array of criterion objects
     */
    private function parseCriteria(string $text): array

    /**
     * Parse budget section for Developmental grants
     * @param string $text Full document text
     * @param string $grantType Grant type (Pilot or Developmental)
     * @return array ['acceptable' => bool, 'modifications' => string]
     */
    private function parseBudget(string $text, string $grantType): array
}
```

---

## Outstanding Issues / TODOs

### High Priority

1. **Email Notifications** ⚠️
   - [ ] Send email when reviewer assigned to application
   - [ ] Send email when new discussion message posted
   - [ ] Send email when review deadline approaching
   - [ ] Configure SMTP settings in config.php

2. **Review Deadlines** ⚠️
   - [ ] Add deadline field to assignments table
   - [ ] Display deadlines in reviewer dashboard
   - [ ] Show overdue status with visual indicators
   - [ ] Admin interface to set/extend deadlines

3. **PDF Report Generation** ⚠️
   - [ ] Complete implementation of generate_report.php
   - [ ] Use library like TCPDF or mPDF
   - [ ] Include all reviews, statistics, and discussions
   - [ ] Format for official distribution

### Medium Priority

4. **Search and Filtering**
   - [ ] Search applications by Grant ID, PI name, or title
   - [ ] Filter applications by grant type, status, completion
   - [ ] Filter reviews by score range
   - [ ] Advanced search with multiple criteria

5. **Export Functionality**
   - [ ] Export applications list to CSV/Excel
   - [ ] Export reviews to CSV for analysis
   - [ ] Export discussion threads
   - [ ] Bulk export for archival

6. **Document Parser Enhancements**
   - [ ] Support for PDF review documents
   - [ ] Better handling of complex formatting
   - [ ] Support for tables in review documents
   - [ ] Manual correction interface for misparsed data

7. **User Profile Management**
   - [ ] Allow users to change own password
   - [ ] Profile page with user preferences
   - [ ] Email notification settings
   - [ ] Display preferences (theme, timezone)

### Low Priority

8. **Dashboard Enhancements**
   - [ ] Charts and graphs for statistics
   - [ ] Recent activity feed
   - [ ] Customizable dashboard widgets
   - [ ] Export dashboard as report

9. **Batch Operations**
   - [ ] Bulk assign reviewers to multiple applications
   - [ ] Bulk mark applications as complete
   - [ ] Bulk delete old applications
   - [ ] Batch email to reviewers

10. **Accessibility Improvements**
    - [ ] ARIA labels for screen readers
    - [ ] Keyboard navigation support
    - [ ] High contrast mode
    - [ ] Font size adjustment

11. **Mobile Responsiveness**
    - [ ] Optimize forms for mobile devices
    - [ ] Touch-friendly buttons and controls
    - [ ] Mobile-specific navigation
    - [ ] Responsive tables with horizontal scroll

### Known Issues

1. **Document Parser Limitations**
   - Parser assumes specific document format/structure
   - May fail on documents with non-standard formatting
   - Bullet points sometimes merged into paragraphs
   - **Workaround**: Use provided sample templates

2. **Session Timeout**
   - Default PHP session timeout may log users out unexpectedly
   - **Solution**: Increase session.gc_maxlifetime in php.ini or implement "remember me" feature

3. **File Upload Size**
   - Limited by PHP upload_max_filesize and post_max_size
   - **Current**: 10MB default
   - **TODO**: Add chunked upload for larger files

4. **Browser Compatibility**
   - Tested primarily on Chrome/Firefox
   - IE11 may have CSS issues
   - **TODO**: Add polyfills for older browsers

### Security Considerations

1. **Password Policy** ✅ Implemented
   - Minimum 8 characters enforced
   - Consider: complexity requirements, expiration

2. **Rate Limiting** ⚠️ TODO
   - [ ] Implement login attempt limiting
   - [ ] Add CAPTCHA after failed attempts
   - [ ] API rate limiting for bulk operations

3. **SQL Injection** ✅ Protected
   - All queries use prepared statements
   - PDO parameterized queries throughout

4. **XSS Prevention** ✅ Protected
   - All output escaped via escape() function
   - HTML special characters converted

5. **File Upload Security** ⚠️ Partial
   - File type validation by extension
   - [ ] TODO: Add MIME type verification
   - [ ] TODO: Virus scanning for uploads
   - [ ] TODO: Isolated upload directory

### Performance Optimizations

1. **Database Indexing** ✅ Implemented
   - Indexes on foreign keys
   - Indexes on frequently queried columns
   - Consider: composite indexes for complex queries

2. **Query Optimization** ⚠️ In Progress
   - Some N+1 query issues in view_all_reviews.php
   - [ ] TODO: Implement eager loading for reviews
   - [ ] TODO: Add query caching

3. **Caching** ⚠️ TODO
   - [ ] Cache review statistics
   - [ ] Cache user permissions
   - [ ] Implement Redis/Memcached

4. **Asset Optimization** ⚠️ TODO
   - [ ] Minify CSS and JavaScript
   - [ ] Combine multiple CSS files
   - [ ] Implement CDN for static assets

### Documentation

1. **User Manuals** ⚠️ TODO
   - [ ] Admin user guide
   - [ ] Reviewer user guide
   - [ ] Quick start guide
   - [ ] Video tutorials

2. **API Documentation** ⚠️ TODO
   - [ ] Document all database tables
   - [ ] Document all functions
   - [ ] Add inline code comments
   - [ ] Generate PHPDoc

3. **Deployment Guide** ⚠️ Partial
   - Basic installation documented
   - [ ] TODO: Production deployment checklist
   - [ ] TODO: Backup and recovery procedures
   - [ ] TODO: Upgrade path documentation

---

## Development Roadmap

### Phase 1: Core Functionality ✅ COMPLETE
- [x] User authentication and authorization
- [x] Application CRUD operations
- [x] Review submission and editing
- [x] Document parser for .docx files
- [x] Discussion system
- [x] Basic reporting

### Phase 2: Enhanced Features ✅ COMPLETE
- [x] Bulk user import
- [x] Bulk application import
- [x] Side-by-side review comparison
- [x] Application completion status
- [x] Unread message notifications
- [x] Review statistics
- [x] Direct report upload via "Rpt" button

### Phase 3: Polish & Optimization 🚧 IN PROGRESS
- [x] UI improvements (wider forms, compact buttons)
- [x] Workflow streamlining (prefilled values)
- [ ] Email notifications
- [ ] PDF report generation
- [ ] Search and filtering
- [ ] Performance optimization

### Phase 4: Advanced Features 📋 PLANNED
- [ ] Review deadlines
- [ ] Batch operations
- [ ] Advanced analytics
- [ ] Mobile app
- [ ] API for integrations

---

## Technology Stack

- **Backend**: PHP 8.0.30
- **Database**: MariaDB/MySQL
- **Web Server**: Apache
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Document Parsing**: PHP ZipArchive, XML parsing
- **Security**: bcrypt password hashing, PDO prepared statements
- **Session Management**: PHP native sessions

---

## System Requirements

- **PHP**: >= 8.0
- **MySQL/MariaDB**: >= 5.7
- **Apache**: >= 2.4 with mod_rewrite
- **PHP Extensions**:
  - pdo_mysql
  - zip
  - mbstring
  - session
- **Disk Space**: 1GB minimum (for uploads)
- **Memory**: 256MB PHP memory_limit recommended

---

## Contributors

- Primary development by Claude (Anthropic AI Assistant)
- Project managed by development team
- Testing and feedback by grant review administrators

---

## Version History

- **v1.0** (Initial Release) - Core review system
- **v1.1** - Added discussion forums and statistics
- **v1.2** - Bulk import and UI improvements
- **v1.3** (Current) - Streamlined workflows and prefilled values

---

## License & Support

This is a private project developed for internal use. For support, contact the system administrator or refer to the documentation files in the repository.
