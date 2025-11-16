-- Migration: Add source_title and source_domain fields to products table
-- This enables efficient duplicate detection based on original scraped title + domain
-- The source_title never changes (even if user edits the name field)

ALTER TABLE products
  ADD COLUMN source_title VARCHAR(200) NULL COMMENT 'original scraped title (never changes, for duplicate detection)' AFTER url,
  ADD COLUMN source_domain VARCHAR(255) NULL COMMENT 'normalized domain for duplicate detection (e.g. "netonnet.no")' AFTER source_title,
  ADD INDEX idx_products_source_domain (source_domain),
  ADD INDEX idx_products_source_title_domain (household_id, source_title, source_domain);
