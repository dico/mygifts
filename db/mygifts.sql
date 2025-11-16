-- DB and charset
CREATE DATABASE IF NOT EXISTS mygifts
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE mygifts;

-- 1) Households (families)
-- id: ULID (26-char), generated in the app
CREATE TABLE IF NOT EXISTS households (
  id                CHAR(26) PRIMARY KEY,
  name              VARCHAR(160) NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2) Users (global persons; can belong to multiple households)
CREATE TABLE IF NOT EXISTS users (
  id                   CHAR(26) PRIMARY KEY,          -- ULID
  firstname            VARCHAR(120) NOT NULL,
  lastname             VARCHAR(120) NOT NULL,
  display_name         VARCHAR(160) NULL,             -- valgfritt visningsnavn
  email                VARCHAR(254) NULL,             -- globally unique if provided (NULL allowed)
  mobile               VARCHAR(30) NULL,
  profile_image_url    VARCHAR(500) NULL,             -- optional avatar/profile image
  can_login            TINYINT(1) NOT NULL DEFAULT 0,
  password_hash        VARCHAR(255) NULL,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  is_admin             TINYINT(1) NOT NULL DEFAULT 0, -- system/global admin
  active_household_id  CHAR(26) NULL,                 -- <-- valgt husholdning
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT fk_users_active_house FOREIGN KEY (active_household_id) REFERENCES households(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3) Household membership (many-to-many, with membership-level flags)
CREATE TABLE IF NOT EXISTS household_members (
  household_id      CHAR(26) NOT NULL,
  user_id           CHAR(26) NOT NULL,
  is_family_member  TINYINT(1) NOT NULL DEFAULT 1,  -- 1 = family; 0 = recipient/contact
  is_manager        TINYINT(1) NOT NULL DEFAULT 0,  -- household-level manager/admin
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (household_id, user_id),
  CONSTRAINT fk_hm_house FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_hm_user  FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  INDEX idx_hm_user (user_id),
  INDEX idx_hm_flags (household_id, is_family_member, is_manager)
) ENGINE=InnoDB;

-- 4) Events (scoped to household)
CREATE TABLE IF NOT EXISTS events (
  id                CHAR(26) PRIMARY KEY,
  household_id      CHAR(26) NOT NULL,
  name              VARCHAR(160) NOT NULL,         -- e.g. "Christmas 2025", "Ola's birthday"
  event_date        DATE,
  event_type        ENUM('christmas','birthday','other') NOT NULL DEFAULT 'other',
  honoree_user_id   CHAR(26) NULL,                 -- person being celebrated (optional)
  notes             TEXT,
  created_by        CHAR(26) NOT NULL,             -- user who created the event
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_evt_house   FOREIGN KEY (household_id)   REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_evt_hon     FOREIGN KEY (honoree_user_id)REFERENCES users(id)      ON DELETE SET NULL,
  CONSTRAINT fk_evt_creator FOREIGN KEY (created_by)     REFERENCES users(id)      ON DELETE RESTRICT,
  INDEX idx_evt_date (event_date),
  INDEX idx_evt_house (household_id)
) ENGINE=InnoDB;

-- 5) Products (per household; no global catalog)
CREATE TABLE IF NOT EXISTS products (
  id                CHAR(26) PRIMARY KEY,
  household_id      CHAR(26) NOT NULL,             -- product belongs to exactly one household
  name              VARCHAR(200) NOT NULL,         -- e.g. "Yellow candle holder"
  description       TEXT,
  url               VARCHAR(500),
  image_url         VARCHAR(500),
  default_price     DECIMAL(12,2) NULL,            -- currency with 2 decimals
  currency_code     CHAR(3) NOT NULL DEFAULT 'NOK',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_household
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  INDEX idx_products_household (household_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_links (
  id              CHAR(26) PRIMARY KEY,
  product_id      CHAR(26) NOT NULL,
  store_name      VARCHAR(160) NOT NULL,
  url             VARCHAR(500) NOT NULL,
  price           DECIMAL(12,2) NULL,             -- store-specific price (optional)
  currency_code   CHAR(3) NOT NULL DEFAULT 'NOK',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pl_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_url (product_id, url),
  INDEX idx_pl_product (product_id),
  INDEX idx_pl_active (product_id, is_active)
) ENGINE=InnoDB;

-- 6) (Legacy gifts-tabell er bevisst utelatt.)

-- 6b) Gift orders (én rad per gave/plan – inneholder produkt og pris direkte)
CREATE TABLE IF NOT EXISTS gift_orders (
  id            CHAR(26) PRIMARY KEY,                 -- ULID
  household_id  CHAR(26) NOT NULL,
  event_id      CHAR(26) NULL,                        -- hvilken event ordren hører til
  title         VARCHAR(200) NULL,                    -- valgfri visningstittel (ellers genereres)
  order_type    ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing', -- vi gir / vi mottar
  product_id    CHAR(26) NULL,                        -- hovedprodukt (kan autolages/valgfritt)
  price         DECIMAL(12,2) NULL,                   -- fri pris (kan forhåndsutfylles fra product.default_price)
  status        ENUM('idea','reserved','purchased','given','cancelled') NOT NULL DEFAULT 'idea',
  notes         TEXT,
  purchased_at  DATETIME NULL,                        -- når kjøpt (hvis relevant)
  given_at      DATETIME NULL,                        -- når gitt (hvis relevant)
  created_by    CHAR(26) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_go_house   FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_go_event   FOREIGN KEY (event_id)     REFERENCES events(id)     ON DELETE SET NULL,
  CONSTRAINT fk_go_product FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE SET NULL,
  CONSTRAINT fk_go_creator FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_go_house (household_id),
  INDEX idx_go_event (event_id),
  INDEX idx_go_status (status),
  INDEX idx_go_type_status (order_type, status),
  INDEX idx_go_product (product_id)
) ENGINE=InnoDB;

-- 6c) Gift order participants (flere givere/mottakere per ordre)
CREATE TABLE IF NOT EXISTS gift_order_participants (
  order_id   CHAR(26) NOT NULL,
  user_id    CHAR(26) NOT NULL,
  role       ENUM('giver','recipient') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id, user_id, role),
  CONSTRAINT fk_gop_order FOREIGN KEY (order_id) REFERENCES gift_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_gop_user  FOREIGN KEY (user_id)  REFERENCES users(id)       ON DELETE CASCADE,
  INDEX idx_gop_order_role (order_id, role),
  INDEX idx_gop_user (user_id)
) ENGINE=InnoDB;

-- 7) Wishlist items (scoped to household)
CREATE TABLE IF NOT EXISTS wishlist_items (
  id                 CHAR(26) PRIMARY KEY,
  household_id       CHAR(26) NOT NULL,
  recipient_user_id  CHAR(26) NOT NULL,     -- who wants this
  created_by_user_id CHAR(26) NOT NULL,     -- who added it (can be same as recipient)
  product_id         CHAR(26) NOT NULL,
  url                VARCHAR(500),          -- optional: override product.url
  notes              TEXT,                  -- why it is desired
  priority           TINYINT UNSIGNED NULL, -- 1 = high
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_w_house    FOREIGN KEY (household_id)       REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_w_rec      FOREIGN KEY (recipient_user_id)  REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_w_creator  FOREIGN KEY (created_by_user_id) REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_w_product  FOREIGN KEY (product_id)         REFERENCES products(id)   ON DELETE CASCADE,
  INDEX idx_w_house (household_id),
  INDEX idx_w_recipient (recipient_user_id)
) ENGINE=InnoDB;

-- 8) OAuth identities (Keycloak, etc.)
CREATE TABLE IF NOT EXISTS oauth_identities (
  id                CHAR(26) PRIMARY KEY,
  user_id           CHAR(26) NOT NULL,
  provider          ENUM('keycloak','google','microsoft','apple','github','custom') NOT NULL,
  provider_user_id  VARCHAR(191) NOT NULL,         -- e.g. Keycloak 'sub'
  realm             VARCHAR(120),                  -- optional (Keycloak realm)
  email             VARCHAR(254),                  -- useful for display
  claims_json       JSON NULL,                     -- optionally store token claims
  last_login_at     DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_oid_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_provider_subject (provider, provider_user_id),
  INDEX idx_oid_user (user_id)
) ENGINE=InnoDB;

-- 9) Settings (global or per household)
CREATE TABLE IF NOT EXISTS settings (
  id                  CHAR(26) PRIMARY KEY,
  household_id        CHAR(26) NULL,        -- NULL = global setting
  `key`               VARCHAR(120) NOT NULL,
  `value`             TEXT NOT NULL,        -- store string or JSON
  updated_by_user_id  CHAR(26) NULL,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_set_house FOREIGN KEY (household_id)       REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT fk_set_user  FOREIGN KEY (updated_by_user_id) REFERENCES users(id)      ON DELETE SET NULL,
  UNIQUE KEY uq_settings (household_id, `key`)
) ENGINE=InnoDB;

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
  giver_id     CHAR(26) NULL,                  -- Legacy: kept for backward compatibility, nullable for new items
  recipient_id CHAR(26) NULL,                  -- Legacy: kept for backward compatibility, nullable for new items
  notes        TEXT,                           -- optional notes for this relationship
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gti_template  FOREIGN KEY (template_id)  REFERENCES gift_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_gti_giver     FOREIGN KEY (giver_id)     REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_gti_recipient FOREIGN KEY (recipient_id) REFERENCES users(id)          ON DELETE CASCADE,
  INDEX idx_gti_template (template_id),
  INDEX idx_gti_giver (giver_id),
  INDEX idx_gti_recipient (recipient_id)
) ENGINE=InnoDB;

-- 10c) Gift Template Item Participants (supports multiple givers/recipients per item)
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
