-- Assunto por departamento (NULL = todos os departamentos, igual respostas prontas).
ALTER TABLE conversation_subjects ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER is_active,
    ADD CONSTRAINT conversation_subjects_ibfk_dept FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL;
