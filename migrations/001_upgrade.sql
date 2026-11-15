-- Back up the database first. Check for duplicates before applying this file:
-- SELECT national_code, COUNT(*) FROM cards GROUP BY national_code HAVING COUNT(*) > 1;
-- Resolve duplicates manually, preserving any access logs, before adding the unique key.
CREATE TABLE IF NOT EXISTS access_areas (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT IGNORE INTO access_areas (name) SELECT DISTINCT access_area FROM cards WHERE TRIM(access_area) <> '';
CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(128) PRIMARY KEY, data MEDIUMBLOB NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
ALTER TABLE cards ENGINE=InnoDB;
ALTER TABLE access_logs ENGINE=InnoDB;
ALTER TABLE cards ADD UNIQUE KEY unique_national_code (national_code);
ALTER TABLE access_logs ADD INDEX card_time (card_id, created_at);
