-- 07-demo-mairie.sql v2 — Mairie de demonstration (idempotent, upsert)
SET FOREIGN_KEY_CHECKS=0;

INSERT INTO parent_orgs
  (id, name, type, contact_first_name, contact_last_name, contact_email, contact_phone,
   address_zip, address_city, department, requested_quota, validated_quota, status,
   created_by, validated_by, validated_at, notes, created_at, updated_at)
VALUES
  (9001, 'Mairie de Démoville', 'mairie', 'Agent', 'Démo', 'demo-mairie@assokit.fr', '01 60 00 00 00',
   '91000', 'Démoville', 'Essonne (91)', 50, 10000, 'active',
   18, 18, NOW(), 'Compte de démonstration — reset automatique', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name=VALUES(name), type=VALUES(type), contact_email=VALUES(contact_email),
  address_city=VALUES(address_city), department=VALUES(department),
  validated_quota=VALUES(validated_quota), status=VALUES(status),
  notes=VALUES(notes), updated_at=NOW();

INSERT INTO users
  (id, email, password_hash, first_name, last_name, role, is_active, must_change_password,
   avatar_color, contract_type, parent_org_id, parent_org_role, created_at)
SELECT
  900001, 'demo-mairie@assokit.fr', u.password_hash, 'Agent', 'Mairie Démo', 'member', 1, 0,
  'blue', 'volunteer', 9001, 'admin', NOW()
FROM users u WHERE u.email='demo@assokit.fr' LIMIT 1
ON DUPLICATE KEY UPDATE
  password_hash=VALUES(password_hash), first_name=VALUES(first_name), last_name=VALUES(last_name),
  role=VALUES(role), is_active=VALUES(is_active), org_id=NULL,
  parent_org_id=VALUES(parent_org_id), parent_org_role=VALUES(parent_org_role);

UPDATE organizations SET parent_org_id=9001, parent_org_linked_at=NOW() WHERE id IN (23,24,25);

SET FOREIGN_KEY_CHECKS=1;
