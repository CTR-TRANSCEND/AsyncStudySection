# Asynchronous Grant Review System - Complete System Documentation

## Table of Contents
1. [System Overview](#system-overview)
2. [Site Structure & Hierarchy](#site-structure--hierarchy)
3. [Database Schema](#database-schema)
4. [Page-by-Page Documentation](#page-by-page-documentation)
5. [User Roles & Permissions](#user-roles--permissions)
6. [Key Features & Workflows](#key-features--workflows)
7. [Technical Architecture](#technical-architecture)
8. [Integration Points](#integration-points)
9. [Security Implementation](#security-implementation)
10. [Areas for Improvement](#areas-for-improvement)

---

## System Overview

**Project Name:** Asynchronous Grant Review System
**Purpose:** Web-based platform for managing grant application reviews with asynchronous collaboration
**Primary Users:** Administrators, Reviewers
**Key Functionality:** Application management, reviewer assignment, scoring, discussions, report generation

### Core Problem Solved
The system addresses the challenge of coordinating grant reviews across multiple reviewers by:
- Allowing asynchronous review of applications
- Managing reviewer assignments and anonymous collaboration
- Standardizing scoring criteria across different grant types
- Facilitating discussion while maintaining reviewer anonymity
- Generating consolidated reports from multiple reviews

---

## Site Structure & Hierarchy

```
AsynchronousGrantReview2/
├── Root Level
│   ├── index.php                    # Public landing page
│   ├── login.php                    # Login page
│   ├── logout.php                   # Logout handler
│   ├── profile.php                  # User profile & settings
│   └── health.php                   # Health check endpoint
│
├── admin/                           # Administrator Dashboard
│   ├── dashboard.php                # Admin overview with stats
│   ├── applications.php             # View all applications
│   ├── manage_applications.php      # Manage applications (CRUD)
│   ├── application_detail.php       # Application detail view
│   ├── application_stats.php        # Application statistics
│   ├── users.php                    # User management
│   ├── grant_types.php              # Grant type management
│   ├── study_sections.php           # Study section/IRB management
│   ├── upload.php                   # Upload application files
│   ├── upload_review.php            # Upload external reviews
│   ├── generate_report.php          # Generate consolidated reports
│   ├── admin_discussion.php         # Admin discussion moderation
│   ├── reviewer_analytics.php       # Reviewer performance analytics
│   ├── download_application_template.php
│   └── download_user_template.php
│
├── reviewer/                        # Reviewer Dashboard
│   ├── dashboard.php                # Reviewer overview with assignments
│   ├── applications.php             # List assigned applications
│   ├── review_application.php       # Submit/edit review (with bullet editor)
│   ├── discussions.php              # Discussion threads
│   ├── discussions_enhanced.php     # Enhanced discussion interface
│   ├── upload_review.php            # Upload review attachments
│   ├── view_all_reviews.php         # View all reviews for an application
│   └── view_review_detail.php       # View specific review details
│
├── includes/                        # Shared components
│   ├── header.php                   # Site header with navigation
│   ├── footer.php                   # Site footer with scripts
│   ├── functions.php                # Helper functions
│   ├── session.php                  # Session management
│   ├── auth.php                     # Authentication class
│   ├── security_headers.php         # HTTP security headers
│   ├── DocumentParser.php           # Document parsing utility
│   ├── BulletEditor.php             # Bullet editor helper class
│   ├── session_security.php         # Session security utilities
│   ├── csrf_enhanced.php            # CSRF protection
│   ├── file_validation_enhanced.php # File upload validation
│   ├── sanitize_enhanced.php        # Input sanitization
│   ├── database_helpers.php         # Database helper functions
│   └── [Various utility classes]
│
├── assets/                          # Static assets
│   ├── css/
│   │   ├── style.css                # Main stylesheet
│   │   └── components.css           # UI component styles
│   └── js/
│       ├── main.js                  # Main JavaScript
│       ├── bullet-editor.js         # Bullet point editor module
│       ├── agr-utils.js             # AGR utility functions
│       ├── agr-toast.js             # Toast notification system
│       ├── utilities.js             # Utility functions
│       ├── toast.js                 # Toast notifications
│       ├── modal.js                 # Modal dialogs
│       ├── validation.js            # Form validation
│       └── theme.js                 # Dark/light theme toggle
│
├── config/                          # Configuration files
│   ├── config.php                   # Main configuration
│   └── database.php                 # Database connection
│
├── database/                        # Database files
│   ├── schema.sql                   # Database schema
│   └── migrations/                  # Database migrations
│
├── uploads/                         # Uploaded files
│   └── applications/                # Application PDFs/docs
│
└── tests/                           # Test files
    └── [PHPUnit test files]
```

---

## Database Schema

### Core Tables

#### users
User accounts and authentication
```sql
- id (PK)
- username (unique)
- password_hash
- full_name
- email
- institution
- role (admin/reviewer)
- is_active
- failed_login_attempts
- locked_until
- last_login
- created_at
- updated_at
```

#### applications
Grant applications submitted for review
```sql
- id (PK)
- applicant_name
- application_title
- grant_type_id (FK)
- study_section_id (FK)
- file_path
- status
- created_at
- updated_at
```

#### grant_types
Types of grants with different review criteria
```sql
- id (PK)
- name
- description
- is_active
- created_at
```

#### grant_sections
Scoring sections for each grant type
```sql
- id (PK)
- grant_type_id (FK)
- name (e.g., "Overall Impact", "Innovation")
- description
- weight
- is_scored (boolean)
- score_min
- score_max
- order_index
```

#### study_sections
IRB/Study sections that manage reviews
```sql
- id (PK)
- name
- is_active
- created_at
```

#### assignments
Maps reviewers to applications with anonymity
```sql
- id (PK)
- application_id (FK)
- reviewer_id (FK)
- anonymous_label (e.g., "Reviewer A", "Reviewer B")
- assigned_at
```

#### reviews
Submitted reviews for applications
```sql
- id (PK)
- application_id (FK)
- reviewer_id (FK)
- overall_score
- overall_comments
- recommendation
- status
- submitted_at
- created_at
- updated_at
```

#### review_criteria_scores
Scores for each grant section
```sql
- id (PK)
- review_id (FK)
- grant_section_id (FK)
- score
- strengths
- weaknesses
- comments
```

#### review_section_scores
Legacy section scoring (non-grant-type reviews)
```sql
- id (PK)
- review_id (FK)
- section_name
- score
- strengths
- weaknesses
```

#### discussion_messages
 threaded discussion messages for applications
```sql
- id (PK)
- application_id (FK)
- user_id (FK)
- message
- parent_id (self-FK for threading)
- created_at
```

#### audit_log
Security and compliance logging
```sql
- id (PK)
- table_name
- record_id
- field_name
- old_value
- new_value
- action
- user_id
- created_at
```

#### login_attempts
Failed login attempt tracking
```sql
- id (PK)
- username
- ip_address
- attempt_time
- success
```

### Junction Tables

#### study_section_reviewers
Maps reviewers to study sections
```sql
- study_section_id (FK)
- reviewer_id (FK)
```

#### study_section_grant_types
Maps grant types to study sections
```sql
- study_section_id (FK)
- grant_type_id (FK)
```

---

## Page-by-Page Documentation

### Root Level Pages

#### index.php
**Purpose:** Public landing page
**Access:** Public
**Features:**
- Displays system welcome message
- Shows login form if not authenticated
- Redirects to role-based dashboard after login

#### login.php
**Purpose:** User authentication
**Access:** Public
**Features:**
- Username/password login form
- Displays default credentials in dev mode
- Role-based redirects after login:
  - Admin → admin/dashboard.php
  - Reviewer → reviewer/dashboard.php
- Progressive account lockout after failed attempts
- Security headers and CSRF protection

#### logout.php
**Purpose:** Secure logout
**Access:** Authenticated users
**Features:**
- Destroys session
- Clears remember-me tokens
- Redirects to login page

#### profile.php
**Purpose:** User profile management
**Access:** Authenticated users
**Features:**
- Update full name, email, institution
- Change password (with strength validation)
- Password change optional (leave blank to keep current)

### Admin Pages

#### admin/dashboard.php
**Purpose:** Admin overview with key metrics
**Access:** Admin only
**Features:**
- Statistics cards: Total applications, pending reviews, completed reviews, active users
- Quick action buttons: Create application, Add user, Upload review
- Recent activity feed
- System health indicators
- Reviewer analytics overview

#### admin/applications.php (aka "View All")
**Purpose:** List all applications in system
**Access:** Admin only
**Features:**
- Paginated table of all applications
- Filter by status, study section, grant type
- Search by applicant name or title
- Quick actions: View, Edit, Delete
- Export functionality

#### admin/manage_applications.php
**Purpose:** Full CRUD operations for applications
**Access:** Admin only
**Features:**
- Create new application with file upload
- Edit application details
- Assign reviewers to applications
- View application status and progress
- Bulk operations (assign, delete, export)

#### admin/application_detail.php
**Purpose:** Detailed single application view
**Access:** Admin only
**Features:**
- Full application details
- Download application file
- View assigned reviewers
- View all submitted reviews
- Review status tracking
- Discussion thread access
- Admin moderation tools

#### admin/application_stats.php
**Purpose:** Application statistics and analytics
**Access:** Admin only
**Features:**
- Application counts by status
- Review completion rates
- Average scores by grant type
- Reviewer performance metrics
- Time-based statistics (daily, weekly, monthly)

#### admin/users.php
**Purpose:** User management
**Access:** Admin only
**Features:**
- List all users with roles
- Create new users (admin/reviewer)
- Edit user details
- Deactivate/activate users
- Reset user passwords
- View user activity

#### admin/grant_types.php
**Purpose:** Grant type management
**Access:** Admin only
**Features:**
- Create/edit grant types
- Define scoring sections for each grant type
- Set section weights and score ranges
- Activate/deactivate grant types

#### admin/study_sections.php
**Purpose:** Study section (IRB) management
**Access:** Admin only
**Features:**
- Create/edit study sections
- Assign reviewers to study sections
- Link grant types to study sections
- Activate/deactivate sections

#### admin/upload.php
**Purpose:** Upload application files
**Access:** Admin only
**Features:**
- Upload PDF/Word documents
- File validation (type, size)
- Parse document metadata
- Create application from upload

#### admin/upload_review.php (Submenu of "Manage Applications")
**Purpose:** Upload external reviews
**Access:** Admin only
**Features:**
- Upload external review files
- Associate review with application
- Parse and display review content
- Convert to system format

#### admin/generate_report.php
**Purpose:** Generate consolidated review reports
**Access:** Admin only
**Features:**
- Select application for report
- Aggregate all reviews
- Calculate average scores
- Generate PDF/Word report
- Include reviewer anonymity

#### admin/admin_discussion.php
**Purpose:** Admin discussion moderation
**Access:** Admin only
**Features:**
- View all discussion threads
- Moderate inappropriate content
- Admin announcements
- Thread management

#### admin/reviewer_analytics.php
**Purpose:** Reviewer performance analytics
**Access:** Admin only
**Features:**
- Review completion rates per reviewer
- Average scores given
- Time to complete reviews
- Review quality metrics
- Comparison charts

### Reviewer Pages

#### reviewer/dashboard.php
**Purpose:** Reviewer overview with assignments
**Access:** Reviewer only
**Features:**
- Statistics: Total assigned, reviewed, pending
- List of assigned applications
- Group by study section
- Unread message indicators
- Quick access to review forms

#### reviewer/applications.php
**Purpose:** List assigned applications
**Access:** Reviewer only
**Features:**
- All applications assigned to reviewer
- Filter by status (pending, in progress, completed)
- Search functionality
- Quick actions: Review, View discussions

#### reviewer/review_application.php
**Purpose:** Submit/edit review (Main review interface)
**Access:** Reviewer only (assigned reviewers only)
**Features:**
- **Bullet point editor** for strengths/weaknesses (NEW)
- Score each grant section (1-9 scale)
- Text comments for each section
- Character limits and validation
- Auto-save drafts to localStorage
- Preview mode
- Copy/paste support
- Overall score and recommendation
- Submit for finalization

#### reviewer/discussions.php
**Purpose:** Discussion threads for applications
**Access:** Reviewer only (assigned reviewers)
**Features:**
- Threaded discussions
- Anonymous posting (shows "Reviewer A", "Reviewer B", etc.)
- Reply to messages
- File attachments
- Search discussions
- Read/unread indicators

#### reviewer/discussions_enhanced.php
**Purpose:** Enhanced discussion interface
**Access:** Reviewer only
**Features:**
- Real-time updates
- Rich text editor
- Better threading display
- Improved navigation

#### reviewer/upload_review.php
**Purpose:** Upload review attachments
**Access:** Reviewer only
**Features:**
- Upload supporting documents
- Attach to specific review
- File validation

#### reviewer/view_all_reviews.php
**Purpose:** View all reviews for an application
**Access:** Reviewer only (assigned reviewers)
**Features:**
- See all submitted reviews
- Anonymous reviewer labels
- Compare scores across sections
- Peer comparison
- Download full report

#### reviewer/view_review_detail.php
**Purpose:** View specific review details
**Access:** Reviewer only
**Features:**
- Full review content
- Section scores
- Comments and feedback
- Timestamps and version history

---

## User Roles & Permissions

### Admin Role
**Permissions:**
- Full access to all admin pages
- Create and manage users
- Create and manage grant types and study sections
- Upload and manage applications
- Assign reviewers to applications
- View all reviews and discussions
- Moderate discussions
- Generate reports
- View analytics and statistics

**Key Responsibilities:**
- System configuration
- User management
- Application oversight
- Report generation

### Reviewer Role
**Permissions:**
- Access assigned applications only
- Submit and edit own reviews
- Participate in discussions for assigned applications
- View other reviews for assigned applications (anonymized)
- Upload review attachments
- Manage own profile

**Restrictions:**
- Cannot access admin pages
- Cannot view unassigned applications
- Cannot see real reviewer names (only anonymous labels)
- Cannot modify submitted reviews (until recalled)

---

## Key Features & Workflows

### 1. Application Submission Workflow
```
1. Admin uploads application file (upload.php)
2. System parses file and creates application record
3. Admin selects grant type and study section
4. Admin assigns reviewers (manage_applications.php)
5. Reviewers see application on dashboard
```

### 2. Review Submission Workflow
```
1. Reviewer logs in → redirected to dashboard
2. Dashboard shows assigned applications
3. Reviewer clicks application → review_application.php
4. Reviewer uses bullet editor for strengths/weaknesses
5. Reviewer scores each section (1-9)
6. System auto-saves drafts to localStorage
7. Reviewer submits final review
8. Review locked (editable only if recalled by admin)
```

### 3. Discussion Workflow
```
1. Reviewer navigates to discussions.php
2. Selects application from list
3. Posts message (shows as "Reviewer A", "Reviewer B")
4. Other reviewers see message and can reply
5. Threaded responses maintain context
6. Admin can moderate if needed
```

### 4. Report Generation Workflow
```
1. Admin selects application (generate_report.php)
2. System retrieves all submitted reviews
3. Calculates average scores per section
4. Aggregates comments
5. Generates PDF/Word report
6. Maintains reviewer anonymity
```

---

## Technical Architecture

### Backend Stack
- **Language:** PHP 8.1+
- **Database:** MySQL 8.0+
- **Web Server:** Apache 2.4+ (with mod_php)
- **Architecture:** Traditional MVC-lite pattern

### Frontend Stack
- **Core:** Vanilla JavaScript (ES6+)
- **Styling:** Custom CSS with CSS variables
- **Components:** Custom AGR namespace modules
- **Testing:** Playwright E2E tests

### Key Technical Components

#### Authentication & Authorization
- Session-based authentication
- Password hashing (bcrypt via password_hash())
- Progressive account lockout (SPEC-AUTH-001)
- Role-based access control (RBAC)
- CSRF protection on all forms

#### Bullet Editor Module (NEW)
**File:** assets/js/bullet-editor.js
**Purpose:** Advanced bullet point editing for reviews

**Features:**
- ContentEditable-based editing
- Auto-create bullets on Enter
- Auto-delete empty bullets on Backspace
- Keyboard navigation (arrows, Tab for indent)
- Character counter with warnings
- Preview mode toggle
- Auto-save to localStorage
- XSS prevention
- WCAG 2.1 AA accessibility

**Dependencies:**
- AGR.Utils (utility functions)
- AGR.Toast (notifications)

#### Discussion System
- Threaded message support
- Anonymous reviewer labels
- File attachments
- Real-time unread indicators
- Search functionality

#### Report Generation
- Multi-review aggregation
- Section-by-section scoring
- Comment consolidation
- PDF/Word export
- Anonymity preservation

---

## Security Implementation

### Authentication Security
- **Password hashing:** bcrypt (PASSWORD_DEFAULT)
- **Session management:** Secure cookies with httponly
- **Progressive lockout:** 3 attempts → 5min lockout, escalates with more failures
- **Session timeout:** Configurable inactivity timeout
- **Remember-me:** Secure token storage

### Input Validation & Sanitization
- **XSS prevention:** htmlspecialchars() on all output
- **SQL injection:** Parameterized queries (PDO prepared statements)
- **CSRF protection:** Token validation on all POST requests
- **File upload validation:** Type, size, and content validation

### HTTP Security Headers
- Content-Security-Policy (configurable)
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff
- Strict-Transport-Security (HTTPS only)

### Audit Logging
- All CRUD operations logged
- Track field changes (old/new values)
- User attribution
- Timestamped records

---

## Integration Points

### Document Parser
**File:** includes/DocumentParser.php
**Purpose:** Parse uploaded PDF/Word files to extract metadata

### Database Helpers
**File:** includes/database_helpers.php
**Purpose:** Common database operations

### Security Headers
**File:** includes/security_headers.php
**Purpose:** Apply HTTP security headers

### Configuration Management
**File:** config/config.php
**Purpose:** Centralized configuration with .env support

---

## Current Issues & Known Limitations

### 1. Bullet Editor Initialization Issue (FIXED)
**Problem:** Bullet editor not displaying due to missing AGR dependencies
**Solution:** Created AGR.Utils and AGR.Toast modules
**Status:** Resolved

### 2. Profile Page Deprecation Warning (FIXED)
**Problem:** htmlspecialchars() receiving null values
**Solution:** Added null check to escape() function
**Status:** Resolved

### 3. Test Failures
**Current Pass Rate:** 67% (148/221 tests passing)
**Main Failure Categories:**
- Bullet editor tests (20/31 fail) - test data issues
- Admin workflow tests - form validation timing
- Form validation tests - field validation errors

### 4. Navigation Structure
**Recent Change:** "Upload Reviews" moved to submenu of "Manage Applications"
**Consistency:** Other menus should follow similar pattern for related items

---

## Areas for Improvement

### User Experience (UX)

1. **Reviewer Dashboard**
   - Add visual progress indicators for each application
   - Show deadline reminders
   - Add bulk action capabilities

2. **Review Interface**
   - [PARTIALLY COMPLETE] Bullet editor for structured feedback
   - Add autosave status indicator
   - Add keyboard shortcuts toolbar
   - Improve mobile responsiveness

3. **Navigation**
   - [IN PROGRESS] Consistent submenu structure
   - Breadcrumbs for deep pages
   - Recently viewed applications quick access

4. **Discussion System**
   - Real-time notifications for new messages
   - @mention functionality for reviewers
   - Rich text formatting options
   - Emoji support

### Functional Improvements

1. **Review Process**
   - Add review template library
   - Enable draft sharing with admin
   - Add review recall workflow
   - Version history for reviews

2. **Analytics**
   - Reviewer completion rate tracking
   - Score distribution analysis
   - Time-to-complete metrics
   - Inter-reviewer reliability scoring

3. **Reporting**
   - Custom report templates
   - Export to Excel/CSV
   - Include charts and graphs
   - Batch report generation

4. **Administrative**
   - Bulk user import from CSV
   - Bulk application assignment
   - Automated deadline reminders
   - Reviewer conflict of interest management

### Technical Improvements

1. **Code Quality**
   - PSR-12 compliance (partially implemented)
   - Unit test coverage
   - API documentation
   - Error handling standardization

2. **Performance**
   - Database query optimization
   - Caching layer
   - Lazy loading for large data sets
   - CDN for static assets

3. **Security**
   - Implement rate limiting
   - Two-factor authentication (optional)
   - API key management for integrations
   - Regular security audit workflow

4. **Infrastructure**
   - Docker containerization
   - CI/CD pipeline
   - Automated backups
   - Monitoring and alerting

### Accessibility Improvements

1. **WCAG 2.1 AA Compliance**
   - Add skip navigation links
   - Ensure all interactive elements are keyboard accessible
   - Improve color contrast ratios
   - Add ARIA labels throughout

2. **Screen Reader Support**
   - Live regions for dynamic content
   - Proper heading hierarchy
   - Descriptive link text
   - Form error announcements

### Database Schema Improvements

1. **Normalize Review Storage**
   - Separate strengths/weaknesses into dedicated table
   - Add review versioning
   - Store bullet points as structured data

2. **Add Relationships**
   - Application tags/categories
   - Reviewer conflicts of interest
   - Application deadlines
   - Review quality scores

---

## Configuration Files

### Environment Variables (.env)
```bash
BASE_URL=/grant-review
DB_HOST=localhost
DB_NAME=grant_review
DB_USER=<see .env>
DB_PASS=<see .env>
```

### Key Configuration Constants
```php
APP_NAME = "Asynchronous Grant Review System"
PASSWORD_MIN_LENGTH = 8
MAX_LOGIN_ATTEMPTS = 3
SESSION_TIMEOUT = 3600
```

---

## Deployment Notes

### Apache Configuration
- Symbolic link: /var/www/html/grant-review → /home/juhur/PROJECTS/AsynchronousGrantReview2
- Directory permissions: /home/juhur must have o+x execute permission
- Required modules: mod_php, mod_rewrite

### File Permissions
- PHP files: 644 (rw-r--r--)
- JavaScript files: 644 (rw-r--r--)
- Upload directories: 755 (rwxr-xr-x)
- .env file: 600 (rw-------)

---

## Testing

### Test Framework
- **E2E Testing:** Playwright
- **Test Files:** playwright-tests/ directory
- **Current Coverage:** 221 tests across multiple suites

### Test Suites
1. Authentication (auth.spec.ts) - 11 tests
2. Authorization (security.spec.ts) - 30 tests
3. Form Validation (form-validation.spec.ts) - 34 tests
4. Bullet Editor (bullet-editor.spec.ts) - 31 tests
5. Discussion System (discussion.spec.ts) - 17 tests
6. Admin Panel (admin-panel.spec.ts) - 14 tests
7. Reviewer Panel (reviewer-panel.spec.ts) - 14 tests
8. UI Components (ui-components.spec.ts) - 31 tests
9. Security (security.spec.ts) - 22 tests
10. Complete Workflows (admin-complete-workflow.spec.ts, reviewer-complete-workflow.spec.ts) - 17 tests

---

## Maintenance & Support

### Regular Maintenance Tasks
1. Review and purge old audit logs
2. Archive completed reviews
3. Update grant types and scoring sections
4. Review user access and deactivate inactive accounts
5. Generate and review system usage reports

### Backup Strategy
- Database: Daily automated backups
- Uploaded files: Weekly backups
- Configuration: Version controlled (git)

---

## Future Roadmap

### Phase 1: Immediate Improvements (1-2 weeks)
- [ ] Fix all failing tests
- [ ] Complete bullet editor integration
- [ ] Improve review form UX
- [ ] Add comprehensive API documentation

### Phase 2: Enhanced Features (1-2 months)
- [ ] Real-time notifications
- [ ] Advanced analytics dashboard
- [ ] Custom report templates
- [ ] Review template library

### Phase 3: Platform Expansion (3-6 months)
- [ ] API for external integrations
- [ ] Mobile responsive design improvements
- [ ] Multi-language support
- [ ] Advanced search and filtering

---

## Contact & Support

**Development Team:** [Contact Information]
**Documentation Maintainer:** [Name]
**Last Updated:** 2025-01-05
**Version:** 1.0.0

---

## Appendix: Database Relationships

### Entity Relationship Overview

```
users (1) ----< (N) assignments
users (1) ----< (N) reviews
users (1) ----< (N) discussion_messages

applications (1) ----< (N) assignments
applications (1) ----< (N) reviews
applications (1) ----< (N) discussion_messages
applications (N) ----< (1) grant_types
applications (N) ----< (1) study_sections

grant_types (1) ----< (N) grant_sections
grant_sections (1) ----< (N) review_criteria_scores

study_sections (1) ----< (N) study_section_reviewers
study_sections (1) ----< (N) study_section_grant_types

reviews (1) ----< (N) review_criteria_scores
reviews (1) ----< (N) review_section_scores
```

---

*This documentation is intended for system review by another agent to propose usability improvements. All aspects of the current system have been documented to enable comprehensive analysis and recommendations.*
