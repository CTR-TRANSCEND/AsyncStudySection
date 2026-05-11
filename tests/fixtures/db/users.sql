-- Test Users Fixture
-- Contains sample users for testing

-- Structured name columns added per SPEC-NAME-SPLIT-001. Tests that load
-- this fixture verify the same row shape as production.

-- Admin users (password: Admin123!)
INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, role, is_active) VALUES
('admin',  '$2y$10$YourAdminHashHere...',  'System Administrator', 'System',    'Administrator', NULL, 'admin@test.com',  'admin', TRUE),
('admin2', '$2y$10$YourAdmin2HashHere...', 'Secondary Admin',      'Secondary', 'Admin',         NULL, 'admin2@test.com', 'admin', TRUE),
('admin3', '$2y$10$YourAdmin3HashHere...', 'Backup Admin',         'Backup',    'Admin',         NULL, 'admin3@test.com', 'admin', TRUE);

-- Reviewer users (password: Reviewer123!)
INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, role, is_active) VALUES
('reviewer1',  '$2y$10$YourReviewer1HashHere...',  'Alice Johnson, PhD',  'Alice',  'Johnson',  'PhD', 'reviewer1@test.com',  'reviewer', TRUE),
('reviewer2',  '$2y$10$YourReviewer2HashHere...',  'Bob Smith, PhD',      'Bob',    'Smith',    'PhD', 'reviewer2@test.com',  'reviewer', TRUE),
('reviewer3',  '$2y$10$YourReviewer3HashHere...',  'Carol Williams, PhD', 'Carol',  'Williams', 'PhD', 'reviewer3@test.com',  'reviewer', TRUE),
('reviewer4',  '$2y$10$YourReviewer4HashHere...',  'David Brown, PhD',    'David',  'Brown',    'PhD', 'reviewer4@test.com',  'reviewer', TRUE),
('reviewer5',  '$2y$10$YourReviewer5HashHere...',  'Emily Davis, PhD',    'Emily',  'Davis',    'PhD', 'reviewer5@test.com',  'reviewer', TRUE),
('reviewer6',  '$2y$10$YourReviewer6HashHere...',  'Frank Miller, PhD',   'Frank',  'Miller',   'PhD', 'reviewer6@test.com',  'reviewer', TRUE),
('reviewer7',  '$2y$10$YourReviewer7HashHere...',  'Grace Wilson, PhD',   'Grace',  'Wilson',   'PhD', 'reviewer7@test.com',  'reviewer', TRUE),
('reviewer8',  '$2y$10$YourReviewer8HashHere...',  'Henry Taylor, PhD',   'Henry',  'Taylor',   'PhD', 'reviewer8@test.com',  'reviewer', TRUE),
('reviewer9',  '$2y$10$YourReviewer9HashHere...',  'Iris Anderson, PhD',  'Iris',   'Anderson', 'PhD', 'reviewer9@test.com',  'reviewer', TRUE),
('reviewer10', '$2y$10$YourReviewer10HashHere...', 'Jack Thomas, PhD',    'Jack',   'Thomas',   'PhD', 'reviewer10@test.com', 'reviewer', TRUE);

-- Inactive users
INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, role, is_active) VALUES
('inactive_user', '$2y$10$YourInactiveHashHere...', 'Inactive User', 'Inactive', 'User', NULL, 'inactive@test.com', 'reviewer', FALSE);
