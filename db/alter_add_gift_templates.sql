-- Migration: Add gift templates tables
-- Run this on existing databases to add the templates feature

USE mygifts;

-- 10) Gift Templates (scoped to household)
CREATE TABLE IF NOT EXISTS gift_templates (
  id            CHAR(26) PRIMARY KEY,
  household_id  CHAR(26) NOT NULL,
  name          VARCHAR(160) NOT NULL,         -- e.g. "Christmas gifts", "Birthday rotation"
  description   TEXT,                          -- optional notes about the template
  created_by    CHAR(26) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gt_house   FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_gt_creator FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE RESTRICT,
  INDEX idx_gt_house (household_id)
) ENGINE=InnoDB;

-- 10b) Gift Template Items (giver → recipient relationships)
CREATE TABLE IF NOT EXISTS gift_template_items (
  id           CHAR(26) PRIMARY KEY,
  template_id  CHAR(26) NOT NULL,
  giver_id     CHAR(26) NOT NULL,              -- who gives the gift
  recipient_id CHAR(26) NOT NULL,              -- who receives the gift
  notes        TEXT,                           -- optional notes for this relationship
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gti_template  FOREIGN KEY (template_id)  REFERENCES gift_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_gti_giver     FOREIGN KEY (giver_id)     REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_gti_recipient FOREIGN KEY (recipient_id) REFERENCES users(id)          ON DELETE CASCADE,
  INDEX idx_gti_template (template_id),
  INDEX idx_gti_giver (giver_id),
  INDEX idx_gti_recipient (recipient_id)
) ENGINE=InnoDB;
