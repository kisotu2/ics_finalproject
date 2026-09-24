-- Synthetic demonstration data: safe to import after database.sql.
-- Demo password for every account: DemoPass!2026 (change before any deployment).
SET NAMES utf8mb4;

INSERT IGNORE INTO users (full_name,username,employee_number,email,department,password,role,status) VALUES
('Amina Njoroge','amina.njoroge','IRA-001','admin.demo@ira.example','ICT','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','admin','active'),
('David Mwangi','david.mwangi','IRA-002','user.demo@ira.example','Finance','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','user','active'),
('Grace Wanjiku','grace.wanjiku','IRA-003','grace.wanjiku@ira.example','Human Resources','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','user','active'),
('Peter Otieno','peter.otieno','IRA-004','peter.otieno@ira.example','Operations','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','user','active'),
('Faith Chebet','faith.chebet','IRA-005','faith.chebet@ira.example','ICT','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','user','active'),
('Brian Kiptoo','brian.kiptoo','IRA-006','brian.kiptoo@ira.example','Procurement','$2y$12$7kO830q01qTwxfjvrwAN3eDTygXI.jzz.JWKpcJx.lnX9z4VdAS4W','user','active');

INSERT IGNORE INTO laptops (asset_tag,serial_number,brand,model,department,assigned_to,status,purchase_date,warranty_expiry) VALUES
('IRA-LAP-001','SYN-9C21A0','Dell','Latitude 5440','Finance',(SELECT id FROM users WHERE email='user.demo@ira.example'),'Assigned','2025-01-15','2028-01-15'),
('IRA-LAP-002','SYN-9C21A1','HP','ProBook 450 G10','Human Resources',(SELECT id FROM users WHERE email='grace.wanjiku@ira.example'),'Assigned','2024-06-10','2027-06-10'),
('IRA-LAP-003','SYN-9C21A2','Lenovo','ThinkPad E14','Operations',(SELECT id FROM users WHERE email='peter.otieno@ira.example'),'Assigned','2023-02-20','2026-02-20'),
('IRA-LAP-004','SYN-9C21A3','Dell','Latitude 5420','ICT',(SELECT id FROM users WHERE email='faith.chebet@ira.example'),'Maintenance','2021-08-18','2024-08-18'),
('IRA-LAP-005','SYN-9C21A4','HP','EliteBook 840 G7','Procurement',(SELECT id FROM users WHERE email='brian.kiptoo@ira.example'),'Assigned','2020-05-13','2023-05-13'),
('IRA-LAP-006','SYN-9C21A5','Lenovo','ThinkPad L14','ICT',NULL,'Available','2025-03-01','2028-03-01'),
('IRA-LAP-007','SYN-9C21A6','Acer','TravelMate P2','Finance',NULL,'Available','2024-09-20','2027-09-20'),
('IRA-LAP-008','SYN-9C21A7','Dell','Latitude 5410','ICT',NULL,'Retired','2019-01-05','2022-01-05'),
('IRA-LAP-009','SYN-9C21A8','HP','ProBook 440 G8','Operations',NULL,'Available','2022-11-15','2025-11-15'),
('IRA-LAP-010','SYN-9C21A9','Lenovo','ThinkPad E15','Human Resources',NULL,'Available','2023-07-12','2026-07-12');

INSERT IGNORE INTO softwares (software_name,vendor,version,license_type,total_licenses,purchase_date,expiry_date,cost,status) VALUES
('Microsoft 365 Business Standard','Microsoft','2025','Subscription',25,'2025-01-01','2026-12-31',3750.00,'Active'),
('Adobe Acrobat Pro','Adobe','2025','Subscription',8,'2025-01-01','2026-12-31',1920.00,'Active'),
('Bitdefender GravityZone','Bitdefender','7.9','Subscription',30,'2025-02-01','2026-01-31',1680.00,'Active'),
('LibreOffice','The Document Foundation','25.2','Free',100,'2025-01-01',NULL,0.00,'Active');

INSERT IGNORE INTO software_assignments (software_id,user_id,assigned_by,assigned_date,notes)
SELECT s.id,u.id,a.id,'2026-08-01 09:00:00','Synthetic demo assignment' FROM softwares s JOIN users u JOIN users a
WHERE s.software_name='Microsoft 365 Business Standard' AND u.email='user.demo@ira.example' AND a.email='admin.demo@ira.example';

INSERT IGNORE INTO maintenance_records (laptop_id,reported_by,issue_description,repair_cost,repaired_at,status,created_at) VALUES
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-004'),(SELECT id FROM users WHERE email='faith.chebet@ira.example'),'Battery drains quickly and the device shuts down under load.',12000.00,NULL,'in_progress','2026-08-10 08:30:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-005'),(SELECT id FROM users WHERE email='brian.kiptoo@ira.example'),'Keyboard replacement after intermittent key failures.',8500.00,'2025-11-15 16:00:00','resolved','2025-11-12 09:15:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-005'),(SELECT id FROM users WHERE email='brian.kiptoo@ira.example'),'Fan noise and overheating during video calls.',6500.00,'2026-04-22 14:00:00','resolved','2026-04-20 10:20:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-003'),(SELECT id FROM users WHERE email='peter.otieno@ira.example'),'Charging port is loose; monitor before repair.',0.00,NULL,'open','2026-08-08 11:00:00');

INSERT IGNORE INTO device_usage_daily (laptop_id,usage_date,active_hours,crash_count,battery_health_percent) VALUES
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-001'),'2026-08-08',7.50,0,94),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-001'),'2026-08-09',8.10,0,94),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-002'),'2026-08-08',6.20,1,89),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-003'),'2026-08-08',9.20,2,78),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-004'),'2026-08-08',5.40,4,58),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-004'),'2026-08-09',4.80,5,56),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-005'),'2026-08-08',8.70,3,63),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-005'),'2026-08-09',7.90,2,62);

INSERT IGNORE INTO maintenance_risk_predictions (laptop_id,risk_score,risk_level,model_version,factors,predicted_at) VALUES
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-001'),0.1200,'Low','rules-v1','{"warranty":"valid","recent_crashes":0,"repairs":0}','2026-08-10 07:00:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-003'),0.6800,'Medium','rules-v1','{"warranty":"expired","open_maintenance":1,"recent_crashes":2}','2026-08-10 07:00:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-004'),0.9200,'High','rules-v1','{"warranty":"expired","open_maintenance":1,"battery_health":56,"recent_crashes":5}','2026-08-10 07:00:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-005'),0.8100,'High','rules-v1','{"warranty":"expired","repair_history":2,"battery_health":62}','2026-08-10 07:00:00');

INSERT IGNORE INTO approved_areas (name,latitude,longitude,radius_meters,active) VALUES
('Main Campus Demonstration Area',-1.2863890,36.8172230,500,1),
('ICT Support Office Demonstration Area',-1.2901000,36.8204000,250,1);

INSERT IGNORE INTO device_locations (laptop_id,latitude,longitude,accuracy_meters,captured_at,source,consent_status,created_by) VALUES
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-001'),-1.2864200,36.8171800,18.00,'2026-08-10 08:45:00','browser','granted',(SELECT id FROM users WHERE email='user.demo@ira.example')),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-002'),-1.2900800,36.8203500,24.00,'2026-08-10 09:10:00','browser','granted',(SELECT id FROM users WHERE email='grace.wanjiku@ira.example')),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-003'),-1.2800000,36.8300000,35.00,'2026-08-10 10:00:00','browser','granted',(SELECT id FROM users WHERE email='peter.otieno@ira.example'));

INSERT IGNORE INTO location_alerts (laptop_id,location_id,message,status,created_at)
SELECT l.id,d.id,'Synthetic alert: authorised check-in is outside an approved demonstration area.','open','2026-08-10 10:00:01'
FROM laptops l JOIN device_locations d ON d.laptop_id=l.id WHERE l.asset_tag='IRA-LAP-003' AND d.captured_at='2026-08-10 10:00:00';

INSERT IGNORE INTO laptop_history (laptop_id,user_id,admin_id,action_type,action_date) VALUES
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-001'),(SELECT id FROM users WHERE email='user.demo@ira.example'),(SELECT id FROM users WHERE email='admin.demo@ira.example'),'Asset assigned','2026-08-01 09:00:00'),
((SELECT id FROM laptops WHERE asset_tag='IRA-LAP-004'),(SELECT id FROM users WHERE email='faith.chebet@ira.example'),(SELECT id FROM users WHERE email='admin.demo@ira.example'),'Maintenance reported','2026-08-10 08:30:00');
