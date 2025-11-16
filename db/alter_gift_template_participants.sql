-- Add support for multiple participants per template item
-- Similar to gift_order_participants structure

CREATE TABLE IF NOT EXISTS gift_template_item_participants (
  item_id     CHAR(26) NOT NULL,
  user_id     CHAR(26) NOT NULL,
  role        ENUM('giver','recipient') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, user_id, role),
  CONSTRAINT fk_gtip_item FOREIGN KEY (item_id) REFERENCES gift_template_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_gtip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_gtip_item (item_id),
  INDEX idx_gtip_user (user_id)
) ENGINE=InnoDB;

-- Migrate existing data from giver_id/recipient_id columns to participants table
INSERT INTO gift_template_item_participants (item_id, user_id, role, created_at)
SELECT id, giver_id, 'giver', created_at
FROM gift_template_items
WHERE giver_id IS NOT NULL;

INSERT INTO gift_template_item_participants (item_id, user_id, role, created_at)
SELECT id, recipient_id, 'recipient', created_at
FROM gift_template_items
WHERE recipient_id IS NOT NULL;

-- Make the old columns nullable so new items can have NULL values
-- (We're keeping them as backup for now, but new items will use the participants table)
ALTER TABLE gift_template_items MODIFY COLUMN giver_id CHAR(26) NULL;
ALTER TABLE gift_template_items MODIFY COLUMN recipient_id CHAR(26) NULL;

-- If you want to drop the old columns entirely, uncomment these:
-- ALTER TABLE gift_template_items DROP COLUMN giver_id;
-- ALTER TABLE gift_template_items DROP COLUMN recipient_id;
