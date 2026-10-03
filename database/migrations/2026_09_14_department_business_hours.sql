-- Horario de funcionamento passa a ser 100% por departamento.
-- 1) Config do departamento (antes ficava em settings + business_hours global)
ALTER TABLE departments ADD COLUMN business_hours_enabled TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE departments ADD COLUMN business_hours_timezone VARCHAR(50) NULL DEFAULT 'America/Sao_Paulo';
ALTER TABLE departments ADD COLUMN absence_message TEXT NULL;

-- 2) Migra o config global (settings) para os departamentos existentes
UPDATE departments SET business_hours_enabled = COALESCE((SELECT `value` FROM settings WHERE `key` = 'business_hours_enabled' LIMIT 1), '1');
UPDATE departments SET business_hours_timezone = COALESCE((SELECT `value` FROM settings WHERE `key` = 'business_hours_timezone' LIMIT 1), 'America/Sao_Paulo');
UPDATE departments SET absence_message = (SELECT `value` FROM settings WHERE `key` = 'absence_message' LIMIT 1);

-- 3) Departamentos sem grade propria herdam copia da grade global
INSERT INTO business_hours (department_id, day_of_week, is_open, open_time, close_time)
SELECT d.id, bh.day_of_week, bh.is_open, bh.open_time, bh.close_time
FROM departments d
CROSS JOIN business_hours bh
WHERE bh.department_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM business_hours x WHERE x.department_id = d.id);
