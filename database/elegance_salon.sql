-- ============================================================
--  ELEGANCE SALON MANAGEMENT SYSTEM
--  MySQL schema + minimal seed data  (XAMPP / phpMyAdmin)
--
--  HOW TO IMPORT
--  1. Start Apache + MySQL in the XAMPP Control Panel
--  2. Open  http://localhost/phpmyadmin
--  3. Click "Import" -> choose this file -> "Go"
-- ============================================================

CREATE DATABASE IF NOT EXISTS elegance_salon
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE elegance_salon;

-- ------------------------------------------------------------
-- 1) USERS - login accounts (admin / receptionist / stylist)
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  username   VARCHAR(50)  NOT NULL,
  email      VARCHAR(120) NOT NULL,
  phone      VARCHAR(20)  DEFAULT NULL,
  password   VARCHAR(255) NOT NULL,               -- bcrypt hash
  role       ENUM('admin','receptionist','stylist') NOT NULL DEFAULT 'receptionist',
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email    (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2) STAFF - employee profiles (a stylist may link to a user
--    account; receptionists/admins do not need a staff row)
-- ------------------------------------------------------------
CREATE TABLE staff (
  staff_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED DEFAULT NULL UNIQUE,
  name            VARCHAR(100) NOT NULL,
  email           VARCHAR(120) DEFAULT NULL,
  phone           VARCHAR(20)  DEFAULT NULL,
  specialization  VARCHAR(150) DEFAULT NULL,
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,   -- % of service price
  work_days       SET('Mon','Tue','Wed','Thu','Fri','Sat','Sun')
                  NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat',
  shift_start     TIME NOT NULL DEFAULT '09:00:00',
  shift_end       TIME NOT NULL DEFAULT '18:00:00',
  hire_date       DATE DEFAULT NULL,
  status          ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id)
      REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3) STAFF_SHIFTS - date-specific shifts / time-off, shown on
--    the calendar (weekly routine lives in staff.work_days)
-- ------------------------------------------------------------
CREATE TABLE staff_shifts (
  shift_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT UNSIGNED NOT NULL,
  shift_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  shift_type ENUM('regular','overtime','off') NOT NULL DEFAULT 'regular',
  notes      VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shift (staff_id, shift_date, start_time),
  CONSTRAINT fk_shift_staff FOREIGN KEY (staff_id)
      REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4) SERVICES - price list (duration drives slot length)
-- ------------------------------------------------------------
CREATE TABLE services (
  service_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  category         ENUM('Hair','Nails','Facial','Makeup','Spa','Other')
                   NOT NULL DEFAULT 'Other',
  description      TEXT DEFAULT NULL,
  duration_minutes INT UNSIGNED NOT NULL DEFAULT 30,
  price            DECIMAL(10,2) NOT NULL,
  commission_rate  DECIMAL(5,2) DEFAULT NULL,  -- overrides staff default if set
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5) CLIENTS - central client database
-- ------------------------------------------------------------
CREATE TABLE clients (
  client_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(100) NOT NULL,
  email                VARCHAR(120) DEFAULT NULL,
  phone                VARCHAR(20)  NOT NULL,
  gender               ENUM('Male','Female','Other') DEFAULT NULL,
  date_of_birth        DATE DEFAULT NULL,
  address              VARCHAR(255) DEFAULT NULL,
  preferred_stylist_id INT UNSIGNED DEFAULT NULL,   -- client preference
  preferences          TEXT DEFAULT NULL,           -- notes: allergies, likes...
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_clients_name (name),
  KEY idx_clients_phone (phone),
  CONSTRAINT fk_client_stylist FOREIGN KEY (preferred_stylist_id)
      REFERENCES staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6) APPOINTMENTS - bookings (status drives the workflow)
-- ------------------------------------------------------------
CREATE TABLE appointments (
  appointment_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id        INT UNSIGNED NOT NULL,
  staff_id         INT UNSIGNED NOT NULL,
  service_id       INT UNSIGNED NOT NULL,
  appointment_date DATE NOT NULL,
  start_time       TIME NOT NULL,
  end_time         TIME NOT NULL,
  status           ENUM('scheduled','confirmed','completed','cancelled','no_show')
                   NOT NULL DEFAULT 'scheduled',
  notes            VARCHAR(255) DEFAULT NULL,
  booked_by        INT UNSIGNED DEFAULT NULL,        -- users.user_id who booked
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_appt_date (appointment_date, staff_id),
  KEY idx_appt_client (client_id),
  CONSTRAINT fk_appt_client  FOREIGN KEY (client_id)  REFERENCES clients(client_id),
  CONSTRAINT fk_appt_staff   FOREIGN KEY (staff_id)   REFERENCES staff(staff_id),
  CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(service_id),
  CONSTRAINT fk_appt_booker  FOREIGN KEY (booked_by)  REFERENCES users(user_id)
      ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7) SUPPLIERS - who we buy salon supplies from
-- ------------------------------------------------------------
CREATE TABLE suppliers (
  supplier_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  contact_person VARCHAR(100) DEFAULT NULL,
  phone          VARCHAR(20)  DEFAULT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8) INVENTORY_ITEMS - stock levels with low-stock reordering
-- ------------------------------------------------------------
CREATE TABLE inventory_items (
  item_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_name      VARCHAR(120) NOT NULL,
  category       ENUM('Hair Products','Beauty Supplies','Nail Supplies',
                      'Equipment','Retail Products','Other')
                 NOT NULL DEFAULT 'Other',
  sku            VARCHAR(30) DEFAULT NULL,
  quantity       DECIMAL(10,2) NOT NULL DEFAULT 0,
  reorder_level  DECIMAL(10,2) NOT NULL DEFAULT 5,
  unit           VARCHAR(20) NOT NULL DEFAULT 'pcs',
  cost_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
  selling_price  DECIMAL(10,2) DEFAULT NULL,
  supplier_id    INT UNSIGNED DEFAULT NULL,
  last_restocked DATE DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_item_sku (sku),
  KEY idx_item_name (item_name),
  CONSTRAINT fk_item_supplier FOREIGN KEY (supplier_id)
      REFERENCES suppliers(supplier_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9) PURCHASE_ORDERS - auto/manual restock orders
-- ------------------------------------------------------------
CREATE TABLE purchase_orders (
  po_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number     VARCHAR(20) NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  quantity      DECIMAL(10,2) NOT NULL,
  unit_cost     DECIMAL(10,2) NOT NULL,
  total_cost    DECIMAL(10,2) NOT NULL,
  status        ENUM('pending','ordered','received','cancelled')
                NOT NULL DEFAULT 'pending',
  order_date    DATE DEFAULT NULL,
  expected_date DATE DEFAULT NULL,
  received_date DATE DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_number (po_number),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
  CONSTRAINT fk_po_item     FOREIGN KEY (item_id)     REFERENCES inventory_items(item_id),
  CONSTRAINT fk_po_creator  FOREIGN KEY (created_by)  REFERENCES users(user_id)
      ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10) PAYMENTS - one row = one invoice/receipt
-- ------------------------------------------------------------
CREATE TABLE payments (
  payment_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no     VARCHAR(20) NOT NULL,
  appointment_id INT UNSIGNED DEFAULT NULL,
  client_id      INT UNSIGNED DEFAULT NULL,
  description    VARCHAR(255) DEFAULT NULL,
  amount         DECIMAL(10,2) NOT NULL DEFAULT 0,   -- subtotal
  tax_rate       DECIMAL(5,2)  NOT NULL DEFAULT 0,   -- % taken from settings
  discount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount   DECIMAL(10,2) NOT NULL,
  payment_method ENUM('Cash','Card','UPI','Bank Transfer','Other')
                 NOT NULL DEFAULT 'Cash',
  status         ENUM('paid','pending','refunded') NOT NULL DEFAULT 'paid',
  payment_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recorded_by    INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY uq_invoice_no (invoice_no),
  KEY idx_pay_date (payment_date),
  CONSTRAINT fk_pay_appt     FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id)
      ON DELETE SET NULL,
  CONSTRAINT fk_pay_client   FOREIGN KEY (client_id)      REFERENCES clients(client_id)
      ON DELETE SET NULL,
  CONSTRAINT fk_pay_recorder FOREIGN KEY (recorded_by)    REFERENCES users(user_id)
      ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11) NOTIFICATIONS - every email/SMS/system alert is logged
--     here (inbox page + audit trail)
-- ------------------------------------------------------------
CREATE TABLE notifications (
  notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED DEFAULT NULL,   -- staff recipient (inbox)
  recipient       VARCHAR(150) DEFAULT NULL,   -- email address / phone no
  channel         ENUM('system','email','sms') NOT NULL DEFAULT 'system',
  type            ENUM('appointment','inventory','payment','system')
                  NOT NULL DEFAULT 'system',
  subject         VARCHAR(150) DEFAULT NULL,
  message         TEXT,
  status          ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME DEFAULT NULL,
  KEY idx_notif_user (user_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id)
      ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12) FEEDBACK - application feedback form
-- ------------------------------------------------------------
CREATE TABLE feedback (
  feedback_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(120) DEFAULT NULL,
  rating      TINYINT UNSIGNED NOT NULL DEFAULT 5,   -- 1..5 stars
  comments    TEXT NOT NULL,
  status      ENUM('new','reviewed') NOT NULL DEFAULT 'new',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 13) SETTINGS - editable key/value config (admin panel)
-- ------------------------------------------------------------
CREATE TABLE settings (
  setting_key   VARCHAR(50) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ============================================================
--  SEED DATA (minimal: 3 login accounts + services + settings)
-- ============================================================

-- Default passwords (bcrypt):  admin123 / recep123 / style123
-- Change them from the admin panel after first login.
INSERT INTO users (name, username, email, phone, password, role) VALUES
('System Administrator', 'admin',     'admin@elegance.local',     '9800000001',
 '$2y$10$kp73Jf2PlDAgD/YDVL0gk.O7gg22LquRNCwpVMvpPdMvdOGatw8yO', 'admin'),
('Riya Front Desk',      'reception', 'reception@elegance.local', '9800000002',
 '$2y$10$OvdSmTI5B/JP4tVfPpyjsu1OvKcH2EvxCqckAO73ovjypr8FFrZqq', 'receptionist'),
('Priya Sharma',         'stylist1',  'priya@elegance.local',     '9800000003',
 '$2y$10$TBb3KqNaoHxCWDSuEh4fr.Ogs6vay0Gd3jkDhwDjazrSw.JBRkTj2', 'stylist');

-- One staff profile linked to the stylist login
INSERT INTO staff (user_id, name, email, phone, specialization, commission_rate, hire_date)
VALUES (3, 'Priya Sharma', 'priya@elegance.local', '9800000003',
        'Hair styling, colouring', 12.00, '2025-01-15');

-- Service price list
INSERT INTO services (name, category, description, duration_minutes, price, commission_rate) VALUES
('Haircut & Styling',  'Hair',   'Consultation, wash, cut and blow-dry finish.',      45,  500.00, 10.00),
('Hair Colouring',     'Hair',   'Full-head colour with ammonia-free products.',      90, 1500.00, 12.00),
('Hair Spa Treatment', 'Hair',   'Deep-conditioning spa for damaged hair.',           60, 1200.00, 10.00),
('Manicure',           'Nails',  'Nail shaping, cuticle care and polish.',            40,  600.00,  8.00),
('Pedicure',           'Nails',  'Foot soak, exfoliation and polish.',                50,  700.00,  8.00),
('Classic Facial',     'Facial', 'Cleansing, exfoliation, mask and face massage.',    60,  900.00, 10.00),
('Bridal Makeup',      'Makeup', 'Full bridal look with premium products.',          120, 5000.00, 15.00),
('Head Massage',       'Spa',    'Relaxing 30-minute head and shoulder massage.',     30,  400.00,  8.00);

-- Editable site settings (Contact Us page reads the developer_* rows)
INSERT INTO settings (setting_key, setting_value) VALUES
('salon_name',        'Elegance Salon'),
('salon_tagline',     'Where beauty meets elegance'),
('salon_email',       'info@elegancesalon.com'),
('salon_phone',       '+91 98765 43210'),
('salon_address',     '12 Rose Street, Beauty District'),
('opening_hours',     'Mon-Sat: 9:00 AM - 8:00 PM | Sun: 10:00 AM - 5:00 PM'),
('currency_symbol',   '₹'),
('tax_rate',          '5'),
('developer_name',    'Your Developer Name Here'),
('developer_email',   'developer@example.com'),
('developer_phone',   '+91 90000 00000'),
('developer_address', 'Your developer address here');
