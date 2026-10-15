-- Leva Já — MySQL 8 / utf8mb4. Importe no phpMyAdmin (Hostinger).
SET NAMES utf8mb4;
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('customer','courier','admin') NOT NULL DEFAULT 'customer',
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  cpf_cnpj VARCHAR(18) NULL,
  password_hash VARCHAR(255) NOT NULL,
  asaas_customer_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE couriers (
  user_id INT UNSIGNED PRIMARY KEY,
  availability ENUM('offline','available','busy','suspended') NOT NULL DEFAULT 'offline',
  vehicle VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE courier_favorites (
  customer_id INT UNSIGNED NOT NULL, courier_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, courier_id),
  FOREIGN KEY (customer_id) REFERENCES users(id), FOREIGN KEY (courier_id) REFERENCES couriers(user_id)
) ENGINE=InnoDB;
CREATE TABLE deliveries (
  id CHAR(36) PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  external_reference VARCHAR(80) NULL,
  status ENUM('created','dispatching_favorites','dispatching_network','assigned','at_pickup','picked_up','in_transit','at_dropoff','delivered','cancelled','failed') NOT NULL DEFAULT 'created',
  pickup_address VARCHAR(255) NOT NULL, pickup_lat DOUBLE NOT NULL, pickup_lng DOUBLE NOT NULL,
  dropoff_address VARCHAR(255) NOT NULL, dropoff_lat DOUBLE NOT NULL, dropoff_lng DOUBLE NOT NULL,
  recipient_name VARCHAR(120) NULL, recipient_phone VARCHAR(30) NULL, notes VARCHAR(500) NULL,
  distance_m INT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL, fee_cents INT UNSIGNED NOT NULL, total_cents INT UNSIGNED NOT NULL,
  payment_status ENUM('pending','paid','refunded','failed') NOT NULL DEFAULT 'pending',
  courier_id INT UNSIGNED NULL,
  delivery_pin CHAR(4) NOT NULL,
  tracking_token CHAR(32) NOT NULL UNIQUE,
  dispatch_started_at DATETIME NULL, favorites_deadline_at DATETIME NULL, network_opened_at DATETIME NULL,
  assigned_at DATETIME NULL, picked_up_at DATETIME NULL, delivered_at DATETIME NULL, cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- no máximo uma entrega ativa por entregador (defesa extra além da transação)
  active_courier_id INT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('assigned','at_pickup','picked_up','in_transit','at_dropoff'), courier_id, NULL)) VIRTUAL,
  UNIQUE KEY one_active_per_courier (active_courier_id),
  UNIQUE KEY uq_customer_ref (customer_id, external_reference),
  KEY idx_status (status, favorites_deadline_at),
  KEY idx_customer (customer_id, created_at),
  FOREIGN KEY (customer_id) REFERENCES users(id), FOREIGN KEY (courier_id) REFERENCES couriers(user_id)
) ENGINE=InnoDB;
CREATE TABLE delivery_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id CHAR(36) NOT NULL, courier_id INT UNSIGNED NOT NULL,
  stage ENUM('favorites','network') NOT NULL,
  status ENUM('offered','accepted','rejected','expired','revoked') NOT NULL DEFAULT 'offered',
  offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, responded_at DATETIME NULL,
  UNIQUE KEY uq_offer (delivery_id, courier_id),
  KEY idx_courier (courier_id, status, expires_at),
  FOREIGN KEY (delivery_id) REFERENCES deliveries(id), FOREIGN KEY (courier_id) REFERENCES couriers(user_id)
) ENGINE=InnoDB;
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id CHAR(36) NOT NULL,
  method ENUM('pix','card') NOT NULL,
  asaas_payment_id VARCHAR(40) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  value_cents INT UNSIGNED NOT NULL,
  pix_payload TEXT NULL, pix_qr MEDIUMTEXT NULL, invoice_url VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL,
  KEY idx_delivery (delivery_id),
  FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
) ENGINE=InnoDB;
CREATE TABLE courier_location_current (
  courier_id INT UNSIGNED PRIMARY KEY, lat DOUBLE NOT NULL, lng DOUBLE NOT NULL, accuracy_m FLOAT NULL,
  captured_at DATETIME NOT NULL, received_at DATETIME NOT NULL, sequence_no BIGINT NOT NULL, delivery_id CHAR(36) NULL,
  KEY idx_recv (received_at),
  FOREIGN KEY (courier_id) REFERENCES couriers(user_id)
) ENGINE=InnoDB;
CREATE TABLE courier_location_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  courier_id INT UNSIGNED NOT NULL, delivery_id CHAR(36) NULL, lat DOUBLE NOT NULL, lng DOUBLE NOT NULL, accuracy_m FLOAT NULL,
  captured_at DATETIME NOT NULL, received_at DATETIME NOT NULL,
  KEY idx_courier_time (courier_id, received_at), KEY idx_recv (received_at)
) ENGINE=InnoDB;
CREATE TABLE delivery_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id CHAR(36) NOT NULL, event_type VARCHAR(60) NOT NULL, actor_type VARCHAR(20) NOT NULL, actor_id INT UNSIGNED NULL,
  metadata JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_delivery (delivery_id, id)
) ENGINE=InnoDB;
CREATE TABLE webhook_events (event_key VARCHAR(120) PRIMARY KEY, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(80) NOT NULL,
  key_prefix VARCHAR(12) NOT NULL, key_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE idempotency_keys (
  user_id INT UNSIGNED NOT NULL, idem_key VARCHAR(80) NOT NULL, request_hash CHAR(64) NOT NULL, delivery_id CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, idem_key)
) ENGINE=InnoDB;
CREATE TABLE settings (k VARCHAR(60) PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB;
INSERT INTO settings (k, v) VALUES
 ('platform_fee_cents','100'),('base_cents','500'),('per_km_cents','150'),('min_cents','800'),('road_factor','1.3'),
 ('courier_share_pct','80'),('favorites_window_s','60'),('offer_ttl_s','90'),('network_radius_km','8'),
 ('stale_after_s','20'),('max_accuracy_m','100'),('avg_speed_kmh','25'),('gps_retention_days','30');
