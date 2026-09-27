-- Creates the technician login "testingtech" / "testing1234".
--
-- The password is stored the way the app stores every password: a bcrypt hash
-- (what Hash::make() writes into users.password_hash). The /login route checks
-- it with Hash::check() and refuses a row whose `active` is not 1.
--
-- Safe to run more than once: it does nothing if the username already exists.

SET @username := 'testingtech';
-- Job orders and service orders are assigned by email, so the account needs one
-- to be given work. Change it if you want a real mailbox.
SET @email    := 'testingtech@akmiis.com';
-- bcrypt of 'testing1234'
SET @hash     := '$2y$10$GWY5bKbt3VpYEfbdpHtojOwg0dLG1F5p5Te9kh/8JVXoadqeLsNxG';

-- The Technician role (id 2 in RolesSeeder), looked up by name rather than
-- assumed. The mobile app treats role 'technician' / role_id 2 as a technician.
SET @role_id := (SELECT id FROM roles WHERE LOWER(TRIM(role_name)) = 'technician' LIMIT 1);

-- Same organization as most existing technicians, so the account sees the same
-- job orders and service orders they do (queries are scoped by organization_id).
SET @org_id := (
    SELECT organization_id
    FROM users
    WHERE role_id = @role_id
    GROUP BY organization_id
    ORDER BY COUNT(*) DESC
    LIMIT 1
);

INSERT INTO users (
    username, password_hash, email_address,
    first_name, last_name,
    organization_id, role_id, active,
    created_at, updated_at
)
SELECT
    @username, @hash, @email,
    'Testing', 'Tech',
    @org_id, @role_id, 1,
    NOW(), NOW()
FROM DUAL
WHERE @role_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM users WHERE username = @username);

-- Check: one row, role Technician, active 1. No row means the username was
-- already taken or there is no Technician role.
SELECT u.id, u.username, u.email_address, u.organization_id, r.role_name, u.active
FROM users u
LEFT JOIN roles r ON r.id = u.role_id
WHERE u.username = @username;
