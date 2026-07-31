-- Business hours by department (global rules have department_id = NULL)
-- Separate from inbox_business_hours which uses inbox_id
CREATE TABLE IF NOT EXISTS business_hours (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NULL COMMENT 'NULL = global default rule',
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday, 1=Monday ... 6=Saturday',
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    open_time TIME NULL DEFAULT '08:00:00',
    close_time TIME NULL DEFAULT '18:00:00',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dept_day (department_id, day_of_week)
) ENGINE=InnoDB;
