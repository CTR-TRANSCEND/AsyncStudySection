# Installation Checklist

## ✅ Completed Setup Steps

### Database
- [x] Database `grant_review` created
- [x] All 13 tables created successfully:
  - users
  - applications
  - reviews
  - review_criteria_scores
  - review_section_scores
  - assignments
  - grant_types
  - grant_sections
  - study_sections
  - study_section_reviewers
  - discussion_messages
  - audit_log
  - uploaded_files
- [x] Default admin user created (username: admin, password: set via environment)
- [x] InnoDB engine with foreign keys and cascading deletes

### Application Structure
- [x] Configuration files (config.php, database.php)
- [x] Authentication system (auth.php)
- [x] Helper functions (functions.php)
- [x] Document parser (DocumentParser.php)
- [x] Admin interface (multiple pages)
- [x] Reviewer interface (multiple pages)
- [x] Modern CSS styling
- [x] JavaScript utilities
- [x] Upload directory created with permissions

### Features Implemented

#### Admin Features
- [x] Dashboard with statistics
- [x] User management (add/edit/activate/deactivate)
- [x] Password reset functionality
- [x] Grant type and section management
- [x] Study section / program call management
- [x] Document upload and parsing
- [x] Application listing and bulk import
- [x] Application detail view with all reviews
- [x] Reviewer assignment management
- [x] Anonymous label generation
- [x] Discussion monitoring
- [x] Final report generation with statistics
- [x] Audit log viewing

#### Reviewer Features
- [x] Dashboard with assigned applications
- [x] View other reviewers' scores (anonymously)
- [x] Submit and edit reviews
- [x] Configurable review sections
- [x] Overall Impact and Relevance scoring
- [x] Budget evaluation (Developmental grants)
- [x] Upload critique documents
- [x] Discussion/chat system
- [x] Anonymous identity display

#### Security Features
- [x] Role-based access control
- [x] Password hashing (bcrypt)
- [x] SQL injection protection (prepared statements)
- [x] XSS prevention (output escaping)
- [x] Session security (httponly cookies)
- [x] Access validation for all pages
- [x] File upload validation
- [x] Complete audit trail

#### Change Tracking
- [x] Audit log table
- [x] Automatic logging of all changes
- [x] Timestamp tracking
- [x] User attribution
- [x] Old/new value comparison

### Sample Data
- [x] 3 sample review reports included
- [x] Document parser tested with sample format
- [x] Validation for both Pilot and Developmental grants

## 🔧 Post-Installation Steps

### Required
1. **Change admin password**
   - Login as admin
   - Go to Users page
   - Reset admin password

2. **Add reviewer accounts**
   - Navigate to Users
   - Create reviewer accounts
   - Provide credentials to reviewers

3. **Upload sample reports** (testing)
   - Go to Upload Reports
   - Test with files in sampleReports/
   - Verify parsing accuracy

4. **Assign reviewers**
   - Go to Applications
   - Select an application
   - Assign 2-3 reviewers
   - Verify anonymous labels

### Optional
5. **Configure web server**
   - Set up virtual host if needed
   - Configure SSL/HTTPS (recommended)
   - Set proper file permissions
   - Point `BASE_URL` to the deployed path
   - Set `SESSION_COOKIE_SECURE=1` when HTTPS is enabled

6. **Performance optimization**
   - Enable PHP OPcache
   - Configure MySQL query cache
   - Set up database backups

7. **Customization**
   - Update `APP_NAME` via environment variables
   - Customize email addresses
   - Adjust session timeout if needed
   - Move uploads outside web root (set `UPLOAD_DIR`) or keep `uploads/.htaccess` enabled

### Environment Configuration
Set these in your web server or `.env` loader:

- `APP_ENV` (e.g., `production`)
- `APP_DEBUG` (`0` or `1`)
- `BASE_URL` (e.g., `/transcend_grant_review_2.0`)
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `UPLOAD_DIR` (absolute path)
- `SESSION_LIFETIME` (seconds)
- `SESSION_COOKIE_SECURE` (`1` when HTTPS)

Reference: `.env.example`

## 📋 Testing Checklist

### Admin Testing
- [ ] Login as admin
- [ ] Create a new reviewer account
- [ ] Upload a sample review report
- [ ] Assign reviewers to an application
- [ ] View application details
- [ ] Generate final report

### Reviewer Testing
- [ ] Login as reviewer
- [ ] View assigned applications
- [ ] Submit a review with all criteria
- [ ] Edit an existing review
- [ ] Post a discussion message
- [ ] View other reviewers' scores (anonymous)

### Security Testing
- [ ] Try accessing admin pages as reviewer
- [ ] Try accessing unassigned applications as reviewer
- [ ] Verify password hashing in database
- [ ] Test XSS prevention in discussion messages
- [ ] Verify audit log entries for changes

## 📊 System Metrics

- **Total PHP Files**: 33
- **Total Database Tables**: 13
- **Admin Pages**: 13
- **Reviewer Pages**: 7
- **Sample Reports**: 3
- **Review Criteria**: 5
- **Grant Types**: 2

## 🚀 Access Information

### URLs
- **Main**: `/transcend_grant_review_2.0/`
- **Login**: `/transcend_grant_review_2.0/login.php`
- **Admin**: `/transcend_grant_review_2.0/admin/dashboard.php`
- **Reviewer**: `/transcend_grant_review_2.0/reviewer/dashboard.php`

### Default Credentials
```
Username: admin
Password: <set during initial setup - see .env>
```

⚠️ **IMPORTANT**: Change this password immediately after first login!
Default credentials are only displayed on the login screen when `APP_DEBUG=1`.

## 📝 Notes

- All changes are tracked in the audit_log table
- Reviewers remain anonymous to each other
- Admins can see true identities
- File uploads are stored in uploads/ directory
- `uploads/.htaccess` blocks direct web access in Apache (configure Nginx similarly)
- Score ranges are configurable per grant type
- System supports dynamic grant types and study sections

## 🆘 Troubleshooting

### Can't login
- Check database connection in config/database.php
- Verify user exists: `SELECT * FROM users WHERE username='admin';`
- Check is_active flag is TRUE

### Upload fails
- Check uploads/ directory permissions (755)
- Verify PHP upload_max_filesize setting
- Ensure file is .docx format

### Parsing errors
- Verify document format matches samples
- Check for all required sections
- Review REPORT_STRUCTURE_ANALYSIS.md

### Database errors
- Check credentials in config/config.php
- Verify database user has proper permissions
- Test connection: `mysql -u $DB_USER -p$DB_PASS grant_review`

## ✅ System Ready!

The Grant Review Discussion System is fully implemented and ready for use.

For detailed usage instructions, see README_SYSTEM.md
