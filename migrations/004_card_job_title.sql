-- Back up the database before applying this migration once.
-- Existing cards retain the placeholder until their job title is edited.
ALTER TABLE cards ADD COLUMN job_title VARCHAR(150) NOT NULL DEFAULT '---' AFTER national_code;
