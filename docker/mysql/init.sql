-- docker/mysql/init.sql
-- Runs once, automatically, the first time the mysql volume is created
-- (mounted into /docker-entrypoint-initdb.d). MYSQL_DATABASE only creates
-- one database, but this app needs two: its own, and the shared `commun`
-- one used by amana/shared (ref_personnes, ref_roles, audit_logs, ...).
CREATE DATABASE IF NOT EXISTS amana_familles CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS amana_commun   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The root user (created via MYSQL_ROOT_PASSWORD) already has full access;
-- this just makes it explicit and future-proofs against switching to a
-- dedicated non-root app user later.
GRANT ALL PRIVILEGES ON amana_familles.* TO 'root'@'%';
GRANT ALL PRIVILEGES ON amana_commun.*   TO 'root'@'%';
FLUSH PRIVILEGES;
