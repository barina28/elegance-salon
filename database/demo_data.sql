/**
 * ============================================================
 *  ELEGANCE SALON - OPTIONAL DEMO DATA (Step 12)
 *  Populates realistic sample data across all modules so you
 *  can immediately test POS, Reports, Inventory, and Dashboards.
 * ============================================================
 */

-- 1. Additional Staff Accounts (Linked to new users)
INSERT INTO users (name, username, email, phone, password, role) VALUES 
('Meera Nair', 'stylist2', 'meera@elegance.local', '9800000004', '$2y$10$TBb3KqNaoHxCWDSuEh4fr.Ogs6vay0Gd3jkDhwDjazrSw.JBRkTj2', 'stylist'),
('Arjun Verma', 'stylist3', 'arjun@elegance.local', '9800000005', '$2y$10$TBb3KqNaoHxCWDSuEh4fr.Ogs6vay0Gd3jkDjazrSw.JBRkTj2', 'stylist');

INSERT INTO staff (user_id, name, email, phone, specialization, commission_rate, work_days, shift_start, shift_end, hire_date) 
SELECT u.user_id, u.name, u.email, u.phone, 'Nails & Facials', 10.00, 'Tue,Wed,Thu,Fri,Sat', '10:00:00', '19:00:00', '2025-03-01' FROM users u WHERE u.username = 'stylist2';

INSERT INTO staff (user_id, name, email, phone, specialization, commission_rate, work_days, shift_start, shift_end, hire_date) 
SELECT u.user_id, u.name, u.email, u.phone, 'Bridal Makeup & Spa', 11.50, 'Mon,Tue,Wed,Thu,Fri', '09:00:00', '18:00:00', '2025-06-10' FROM users u WHERE u.username = 'stylist3';

-- 2. Suppliers
INSERT INTO suppliers (supplier_id, name, contact_person, phone, email, address) VALUES
(1, 'L\'Oreal Professional Pakistan', 'Tariq Mehmood', '021-35678901', 'orders@lorealpro.pk', 'A-14, SITE Industrial Area, Karachi'),
(2, 'OPI Nail Supply Co.', 'Sana Farooq', '042-35891234', 'sales@opinails.pk', 'Suite 4B, Gulberg III, Lahore'),
(3, 'Schwarzkopf Salon Distributors', 'Bilal Ahmed', '051-2823456', 'support@schwarzkopf.pk', 'Blue Area, Islamabad');

-- 3. Inventory Items (Includes Low Stock & Out of Stock items)
INSERT INTO inventory_items (item_id, name, sku, category, unit, current_stock, reorder_level, cost_price, retail_price, supplier_id) VALUES
(1, 'L\'Oreal Serie Expert Shampoo 500ml', 'LOR-SHP-500', 'Hair Care', 'Bottle', 15, 5, 1200.00, 2200.00, 1),
(2, 'OPI Nail Lacquer - Infinite Shine', 'OPI-NIL-INF', 'Nails', 'Piece', 3, 5, 800.00, 1500.00, 2),
(3, 'Schwarzkopf Professional Hair Color Tube', 'SCH-CLR-01', 'Hair Color', 'Tube', 0, 8, 950.00, 1800.00, 3),
(4, 'Kerastase Nutritive Mask 200ml', 'KER-MSK-200', 'Hair Care', 'Jar', 12, 4, 2500.00, 4500.00, 1),
(5, 'Disposable Salon Towels (Pack of 100)', 'ACC-TOW-100', 'Accessories', 'Pack', 4, 10, 600.00, 1100.00, NULL),
(6, 'OPI ProSpa Nail Cuticle Oil', 'OPI-OIL-10', 'Nails', 'Bottle', 8, 3, 1100.00, 2000.00, 2);

-- 4. Sample Customers
INSERT INTO customers (customer_id, name, phone, email, notes) VALUES
(2, 'Ayesha Khan', '0300-1234567', 'ayesha.khan@example.com', 'Prefers herbal shampoo, allergic to certain dyes.'),
(3, 'Zainab Malik', '0321-9876543', 'zainab.m@example.com', 'Regular bridal client.'),
(4, 'Fatima Noor', '0333-5554433', 'fatima.noor@example.com', 'Sensitive skin.'),
(5, 'Hira Tariq', '0312-4443322', 'hira.tariq@example.com', 'Prefers morning appointments.'),
(6, 'Usman Ahmed', '0302-7778899', 'usman.ahmed@example.com', 'Beard trim and styling.'),
(7, 'Sadaf Jamil', '0345-6667788', 'sadaf.j@example.com', 'Hair spa regular.');

-- 5. Appointments (Relative dates spanning past and future)
INSERT INTO appointments (appointment_id, customer_id, service_id, staff_id, appointment_date, start_time, end_time, status, notes) VALUES
(1, 2, 1, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), '10:00:00', '11:00:00', 'completed', 'Haircut and blowout.'),
(2, 3, 3, 2, DATE_SUB(CURDATE(), INTERVAL 12 DAY), '11:30:00', '13:00:00', 'completed', 'Manicure & pedicure.'),
(3, 4, 2, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY), '14:00:00', '17:00:00', 'completed', 'Bridal trial makeup.'),
(4, 5, 5, 1, DATE_SUB(CURDATE(), INTERVAL 7 DAY), '10:00:00', '11:00:00', 'completed', 'Facial treatment.'),
(5, 6, 4, 2, DATE_SUB(CURDATE(), INTERVAL 5 DAY), '15:00:00', '15:30:00', 'completed', 'Beard trim.'),
(6, 7, 1, 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), '11:00:00', '12:00:00', 'completed', 'Haircut.'),
(7, 2, 6, 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '13:00:00', '14:30:00', 'completed', 'Hair spa.'),
(8, 3, 2, 2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '16:00:00', '19:00:00', 'completed', 'Makeup session.'),
(9, 4, 3, 3, CURDATE(), '10:00:00', '11:30:00', 'completed', 'Nail art.'),
(10, 5, 1, 1, CURDATE(), '12:00:00', '13:00:00', 'completed', 'Haircut.'),
-- 5 Unbilled appointments for POS cashier demo
(11, 6, 4, 2, CURDATE(), '14:00:00', '14:30:00', 'confirmed', 'Beard grooming.'),
(12, 7, 5, 3, CURDATE(), '15:00:00', '16:00:00', 'confirmed', 'Facial cleanup.'),
(13, 2, 1, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', '11:00:00', 'scheduled', 'Trim.'),
(14, 3, 2, 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '11:00:00', '14:00:00', 'scheduled', 'Makeup.'),
(15, 4, 6, 3, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '13:00:00', '14:30:00', 'scheduled', 'Spa.');

-- 6. Payments / Invoices (Matching completed appointments + some standalone sales)
INSERT INTO payments (payment_id, appointment_id, customer_id, total_amount, payment_method, status, payment_date, notes) VALUES
(1, 1, 2, 2500.00, 'cash', 'paid', DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Paid in cash.'),
(2, 2, 3, 2200.00, 'card', 'paid', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'POS Terminal #2.'),
(3, 3, 4, 7500.00, 'upi', 'paid', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Online transfer.'),
(4, 4, 5, 4000.00, 'cash', 'paid', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Cash receipt.'),
(5, 5, 6, 800.00, 'transfer', 'paid', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Bank transfer.'),
(6, 6, 7, 2500.00, 'card', 'paid', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Visa card.'),
(7, 7, 2, 3500.00, 'cash', 'paid', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Cash payment.'),
(8, 8, 3, 5000.00, 'upi', 'paid', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Mobile payment.'),
(9, 9, 4, 2200.00, 'cash', 'paid', CURDATE(), 'Cash payment.'),
(10, 10, 5, 2500.00, 'card', 'paid', CURDATE(), 'Card terminal.');

-- 7. Feedback Submissions
INSERT INTO feedback (feedback_id, name, email, phone, rating, message, status) VALUES
(1, 'Ayesha Khan', 'ayesha.khan@example.com', '0300-1234567', 5, 'Absolute luxury experience! The staff is extremely professional.', 'reviewed'),
(2, 'Zainab Malik', 'zainab.m@example.com', '0321-9876543', 4, 'Loved the bridal makeup trial. Will definitely visit again.', 'pending'),
(3, 'Usman Ahmed', 'usman.ahmed@example.com', '0302-7778899', 5, 'Best beard styling service in town.', 'reviewed'),
(4, 'Hira Tariq', 'hira.tariq@example.com', '0312-4443322', 3, 'Good ambiance, but had to wait 10 minutes past my appointment time.', 'pending');