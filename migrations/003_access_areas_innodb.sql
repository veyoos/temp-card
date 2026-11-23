-- Run once on existing installations after a database backup.
-- InnoDB ensures area changes and card issuance are protected by row locks.
ALTER TABLE access_areas ENGINE=InnoDB;
