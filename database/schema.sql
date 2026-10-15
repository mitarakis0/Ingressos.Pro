-- Selecione o banco ingressos_abacatepay antes de executar este arquivo no HeidiSQL.
-- CREATE TABLE IF NOT EXISTS permite continuar uma importacao parcial sem recriar tabelas existentes.
-- Nomes de FOREIGN KEY usam o prefixo fk_ia_ para evitar conflitos com restricoes antigas.

CREATE TABLE IF NOT EXISTS organizers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  email VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','staff') NOT NULL DEFAULT 'admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_users_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  description TEXT NULL,
  cover_url VARCHAR(500) NULL,
  venue VARCHAR(220) NOT NULL,
  address VARCHAR(300) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  age_rating VARCHAR(40) NULL,
  rules TEXT NULL,
  contact VARCHAR(190) NULL,
  status ENUM('draft','published','closed','cancelled') NOT NULL DEFAULT 'draft',
  sales_start DATETIME NULL,
  sales_end DATETIME NULL,
  total_capacity INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_events_status_starts (status, starts_at),
  CONSTRAINT fk_ia_events_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  category VARCHAR(80) NULL,
  price_cents INT UNSIGNED NOT NULL,
  quantity_total INT UNSIGNED NOT NULL,
  quantity_sold INT UNSIGNED NOT NULL DEFAULT 0,
  max_per_order INT UNSIGNED NOT NULL DEFAULT 5,
  sales_start DATETIME NULL,
  sales_end DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_ticket_event_active (event_id, active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  batch_number INT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  quantity_total INT UNSIGNED NOT NULL,
  quantity_sold INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ia_ticket_batches_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  INDEX idx_batch_active_dates (ticket_type_id, active, starts_at, ends_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS combo_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  people_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
  price_cents INT UNSIGNED NOT NULL,
  quantity_total INT UNSIGNED NOT NULL,
  quantity_sold INT UNSIGNED NOT NULL DEFAULT 0,
  max_per_order TINYINT UNSIGNED NOT NULL DEFAULT 3,
  description VARCHAR(500) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_ia_combo_offers_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_combo_offers_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  code VARCHAR(50) NOT NULL,
  discount_type ENUM('percent','fixed') NOT NULL,
  discount_value INT UNSIGNED NOT NULL,
  min_total_cents INT UNSIGNED NOT NULL DEFAULT 0,
  max_uses INT UNSIGNED NULL,
  uses_count INT UNSIGNED NOT NULL DEFAULT 0,
  per_customer_limit INT UNSIGNED NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupon_org_code (organizer_id, code),
  CONSTRAINT fk_ia_coupons_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id),
  CONSTRAINT fk_ia_coupons_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(32) NOT NULL UNIQUE,
  organizer_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  buyer_name VARCHAR(160) NOT NULL,
  buyer_phone VARCHAR(40) NOT NULL,
  buyer_email VARCHAR(190) NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL,
  coupon_id BIGINT UNSIGNED NULL,
  status ENUM('pending','paid','expired','cancelled','refunded','disputed') NOT NULL DEFAULT 'pending',
  payment_provider VARCHAR(30) NOT NULL DEFAULT 'abacatepay',
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_orders_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id),
  CONSTRAINT fk_ia_orders_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_ia_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  INDEX idx_order_event_status (event_id, status),
  INDEX idx_order_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  combo_offer_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(160) NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  people_per_item TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_ia_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_order_items_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id),
  CONSTRAINT fk_ia_order_items_batch FOREIGN KEY (batch_id) REFERENCES ticket_batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_ia_order_items_combo FOREIGN KEY (combo_offer_id) REFERENCES combo_offers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_participants_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_participants_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  INDEX idx_participant_name (full_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  code CHAR(48) NOT NULL UNIQUE,
  status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
  checked_in_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_tickets_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_tickets_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_ia_tickets_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_ia_tickets_ticket_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id),
  CONSTRAINT fk_ia_tickets_batch FOREIGN KEY (batch_id) REFERENCES ticket_batches(id) ON DELETE SET NULL,
  INDEX idx_ticket_event_status (event_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'abacatepay',
  provider_payment_id VARCHAR(100) NULL UNIQUE,
  amount_cents INT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
  pix_code TEXT NULL,
  pix_qr_base64 MEDIUMTEXT NULL,
  payment_url VARCHAR(500) NULL,
  raw_response JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_payment_order_status (order_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_event_id VARCHAR(120) NOT NULL UNIQUE,
  event_type VARCHAR(100) NOT NULL,
  payload JSON NOT NULL,
  processed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checkins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_checkin_ticket (ticket_id),
  CONSTRAINT fk_ia_checkins_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
  CONSTRAINT fk_ia_checkins_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_ia_checkins_event FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  organizer_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ia_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ia_audit_logs_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id BIGINT UNSIGNED NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_setting_org_key (organizer_id, setting_key),
  CONSTRAINT fk_ia_settings_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO organizers (name, slug, email)
SELECT 'Organizador Principal', 'principal', NULL
WHERE NOT EXISTS (SELECT 1 FROM organizers WHERE slug = 'principal');
