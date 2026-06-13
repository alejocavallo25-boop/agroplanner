ALTER TABLE users
ADD COLUMN has_agricultura TINYINT(1) DEFAULT 0,
ADD COLUMN has_tambo TINYINT(1) DEFAULT 0,
ADD COLUMN has_ganaderia TINYINT(1) DEFAULT 0;

-- Optionally grant admin all modules, and default users pending.
-- There's a chance the existing user '1' is the admin.
UPDATE users SET has_agricultura = 1, has_tambo = 1, has_ganaderia = 1 WHERE role = 'admin';
-- If there are currently active users, they probably had agriculture. Let's give them agriculture.
UPDATE users SET has_agricultura = 1 WHERE status = 'active' AND role != 'admin';
