
-- =====================================================================
-- АИС «Справочник российских автомобилей» (АИС «СРА»)
-- Файл 1 из 2: структура + справочники + демонстрационные данные.
-- Целевая СУБД: MariaDB 10.5+ (ориентир 10.6 LTS). CHECK enforced с 10.2.1.
-- Выполняется в УЖЕ СОЗДАННОЙ выбранной БД (phpMyAdmin: вкладка SQL).
-- CREATE DATABASE / CREATE USER / GRANT не требуются.
-- Повторный импорт пересоздаёт таблицы (DROP TABLE IF EXISTS) — данные будут очищены.
-- Тестовые учётные записи создаются отдельно: php scripts/seed_users.php
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS modifications;
DROP TABLE IF EXISTS car_models;
DROP TABLE IF EXISTS manufacturers;
DROP TABLE IF EXISTS doc_templates;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Пользователи (ТЗ 3.4.4 + служебные счётчики попыток) ----------
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user',
    email VARCHAR(512) NOT NULL COMMENT 'AES-256-GCM, base64',
    phone VARCHAR(512) NULL COMMENT 'AES-256-GCM, base64',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_password_change DATETIME NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    lock_until DATETIME NULL,
    login_fail_count INT NOT NULL DEFAULT 0,
    code_fail_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login (login),
    CONSTRAINT chk_users_role CHECK (role IN ('admin', 'operator', 'user')),
    CONSTRAINT chk_users_locked CHECK (is_locked IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------- Производители ----------
CREATE TABLE manufacturers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(60) NOT NULL DEFAULT 'Россия',
    founded_year SMALLINT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uq_manufacturers_name (name),
    CONSTRAINT chk_manufacturers_year CHECK (founded_year BETWEEN 1800 AND 2100),
    CONSTRAINT chk_manufacturers_status CHECK (status IN ('active', 'archived'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------- Модели автомобилей ----------
CREATE TABLE car_models (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    manufacturer_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    body_type VARCHAR(30) NOT NULL,
    production_start_year SMALLINT NOT NULL,
    production_end_year SMALLINT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'in_production',
    PRIMARY KEY (id),
    UNIQUE KEY uq_car_models_mf_name (manufacturer_id, name),
    KEY idx_car_models_status (status),
    CONSTRAINT fk_car_models_manufacturer FOREIGN KEY (manufacturer_id)
        REFERENCES manufacturers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_car_models_body CHECK (body_type IN
        ('седан', 'хэтчбек', 'лифтбек', 'универсал', 'купе', 'кроссовер',
         'внедорожник', 'минивэн', 'фургон', 'пикап')),
    CONSTRAINT chk_car_models_start CHECK (production_start_year BETWEEN 1900 AND 2100),
    CONSTRAINT chk_car_models_end CHECK (production_end_year IS NULL
        OR production_end_year BETWEEN 1900 AND 2100),
    CONSTRAINT chk_car_models_years CHECK (production_end_year IS NULL
        OR production_end_year >= production_start_year),
    CONSTRAINT chk_car_models_status CHECK (status IN ('in_production', 'discontinued'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------- Модификации ----------
CREATE TABLE modifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    car_model_id INT UNSIGNED NOT NULL,
    code VARCHAR(60) NOT NULL,
    engine_type VARCHAR(20) NOT NULL,
    engine_volume_cm3 INT NULL,
    power_hp SMALLINT NOT NULL,
    transmission VARCHAR(20) NOT NULL,
    drive_type VARCHAR(20) NOT NULL,
    year_start SMALLINT NOT NULL,
    year_end SMALLINT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'in_production',
    PRIMARY KEY (id),
    UNIQUE KEY uq_modifications_code (code),
    KEY idx_modifications_model (car_model_id),
    CONSTRAINT fk_modifications_model FOREIGN KEY (car_model_id)
        REFERENCES car_models (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_modifications_engine CHECK (engine_type IN ('бензин', 'дизель', 'гибрид', 'электро', 'газ')),
    CONSTRAINT chk_modifications_volume CHECK (engine_volume_cm3 IS NULL
        OR engine_volume_cm3 BETWEEN 500 AND 20000),
    CONSTRAINT chk_modifications_power CHECK (power_hp BETWEEN 30 AND 2000),
    CONSTRAINT chk_modifications_transmission CHECK (transmission IN ('механика', 'автомат', 'робот', 'вариатор', 'редуктор')),
    CONSTRAINT chk_modifications_drive CHECK (drive_type IN ('передний', 'задний', 'полный')),
    CONSTRAINT chk_modifications_years CHECK (year_start BETWEEN 1900 AND 2100
        AND (year_end IS NULL OR (year_end BETWEEN 1900 AND 2100 AND year_end >= year_start))),
    CONSTRAINT chk_modifications_status CHECK (status IN ('in_production', 'discontinued'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------- Журнал действий (ТЗ 3.4.3в, 3.4.4) ----------
-- Ссылка на изменённую сущность логическая (entity_type + entity_id): сущностей несколько,
-- один физический FK на все таблицы невозможен. FK на пользователя — с SET NULL,
-- чтобы журнал переживал удаление учётной записи.
CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    entity_type VARCHAR(32) NULL,
    entity_id INT UNSIGNED NULL,
    action VARCHAR(16) NOT NULL,
    state_before MEDIUMTEXT NULL COMMENT 'JSON состояния до, AES-256-GCM',
    state_after MEDIUMTEXT NULL COMMENT 'JSON состояния после, AES-256-GCM',
    ip_address VARCHAR(512) NULL COMMENT 'AES-256-GCM, base64',
    `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_time (`timestamp`),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_audit_action CHECK (action IN
        ('login', 'logout', 'create', 'read', 'update', 'delete', 'import',
         'export', 'restore', 'lock', 'unlock', 'login_failed', 'code_failed'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------- Шаблоны печатных форм (управление — администратор, ТЗ 2.3) ----------
CREATE TABLE doc_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    title VARCHAR(120) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doc_templates_code (code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- =====================================================================
-- Демонстрационные данные предметной области.
-- Ссылок на тестовых пользователей нет: журнал наполняется при работе
-- (первые записи создаёт seed_users.php с фактическими id пользователей).
-- =====================================================================

INSERT INTO manufacturers (id, name, country, founded_year, status) VALUES
(1, 'АВТОВАЗ', 'Россия', 1966, 'active'),
(2, 'ГАЗ',     'Россия', 1932, 'active'),
(3, 'УАЗ',     'Россия', 1941, 'active'),
(4, 'Москвич', 'Россия', 1930, 'active'),
(5, 'Aurus',   'Россия', 2018, 'active'),
(6, 'Evolute', 'Россия', 2022, 'active');

INSERT INTO car_models (id, manufacturer_id, name, body_type, production_start_year, production_end_year, status) VALUES
(1,  1, 'Vesta',       'седан',       2015, NULL, 'in_production'),
(2,  1, 'Granta',      'седан',       2011, NULL, 'in_production'),
(3,  1, 'Niva Legend', 'внедорожник', 1977, NULL, 'in_production'),
(4,  1, 'Largus',      'универсал',   2012, NULL, 'in_production'),
(5,  2, 'Газель Next', 'фургон',      2013, NULL, 'in_production'),
(6,  2, 'Соболь NN',   'фургон',      2021, NULL, 'in_production'),
(7,  3, 'Патриот',     'внедорожник', 2005, NULL, 'in_production'),
(8,  3, 'Хантер',      'внедорожник', 2003, NULL, 'in_production'),
(9,  4, 'Москвич 3',   'кроссовер',   2022, NULL, 'in_production'),
(10, 4, 'Москвич 412', 'седан',       1967, 2001, 'discontinued'),
(11, 5, 'Senat',       'седан',       2018, NULL, 'in_production'),
(12, 6, 'i-Pro',       'седан',       2022, NULL, 'in_production'),
(13, 6, 'i-Joy',       'кроссовер',   2022, NULL, 'in_production');

INSERT INTO modifications (id, car_model_id, code, engine_type, engine_volume_cm3, power_hp, transmission, drive_type, year_start, year_end, status) VALUES
(1,  1,  'VESTA-16-MT',     'бензин', 1598, 106, 'механика', 'передний', 2015, NULL, 'in_production'),
(2,  1,  'VESTA-18-AMT',    'бензин', 1774, 122, 'робот',    'передний', 2015, 2019, 'discontinued'),
(3,  1,  'VESTA-CNG',       'газ',    1598,  90, 'механика', 'передний', 2019, NULL, 'in_production'),
(4,  2,  'GRANTA-90-MT',    'бензин', 1598,  90, 'механика', 'передний', 2011, NULL, 'in_production'),
(5,  3,  'NIVA-17-MT',      'бензин', 1690,  83, 'механика', 'полный',   1977, NULL, 'in_production'),
(6,  5,  'GAZEL-28-UMZ',    'бензин', 2890, 107, 'механика', 'задний',   2013, NULL, 'in_production'),
(7,  5,  'GAZEL-28-D',      'дизель', 2776, 150, 'механика', 'задний',   2013, NULL, 'in_production'),
(8,  7,  'PATRIOT-27-MT',   'бензин', 2693, 150, 'механика', 'полный',   2005, NULL, 'in_production'),
(9,  7,  'PATRIOT-27-AT',   'бензин', 2693, 150, 'автомат',  'полный',   2020, NULL, 'in_production'),
(10, 9,  'MOSKVICH3-15-CVT','бензин', 1499, 136, 'вариатор', 'передний', 2022, NULL, 'in_production'),
(11, 10, 'M412-15-MT',      'бензин', 1479,  75, 'механика', 'задний',   1967, 2001, 'discontinued'),
(12, 11, 'SENAT-44-HYB',    'гибрид', 4400, 598, 'автомат',  'полный',   2018, NULL, 'in_production'),
(13, 12, 'IPRO-EV',         'электро', NULL, 150, 'редуктор', 'передний', 2022, NULL, 'in_production'),
(14, 13, 'IJOY-EV',         'электро', NULL, 163, 'редуктор', 'передний', 2022, NULL, 'in_production'),
(15, 4,  'LARGUS-90-MT',    'бензин', 1598,  90, 'механика', 'передний', 2012, NULL, 'in_production'),
(16, 6,  'SOBOL-25-MT',     'бензин', 2499, 149, 'механика', 'задний',   2021, NULL, 'in_production'),
(17, 8,  'HUNTER-27-MT',    'бензин', 2693, 128, 'механика', 'полный',   2003, NULL, 'in_production');

-- Тексты шаблонов совпадают с template_defaults() в src/pdf.php (кнопка «Текст по умолчанию»).
INSERT INTO doc_templates (code, title, body) VALUES
('car_card', 'Карточка автомобиля', 'КАРТОЧКА АВТОМОБИЛЯ
Производитель: {{manufacturer_name}} ({{country}}, год основания {{founded_year}})
Модель: {{model_name}}
Тип кузова: {{body_type}}
Годы производства: {{production_years}}
Статус производства: {{status_label}}
Число модификаций: {{modifications_count}}

Модификации:
{{ROWS}}
{{code}} | {{engine_type}} | {{engine_volume}} | {{power_hp}} л.с. | {{transmission}} | привод {{drive_type}} | {{years}}
{{ENDROWS}}

Сформировано: {{generated_at}}. АИС «Справочник российских автомобилей»'),
('model_info', 'Справка о модели', 'СПРАВКА О МОДЕЛИ
Модель: {{model_name}}
Производитель: {{manufacturer_name}} ({{country}})
Кузов: {{body_type}}. Выпуск: {{production_years}}
Статус производства: {{status_label}}
Модификаций в справочнике: {{modifications_count}}
Диапазон мощности: {{power_range}} л.с.

Справка сформирована по данным базы: {{generated_at}}'),
('manufacturer_report', 'Отчёт по производителям', 'ОТЧЁТ ПО ПРОИЗВОДИТЕЛЯМ
Дата формирования: {{generated_at}}
Всего производителей: {{total_manufacturers}}

{{ROWS}}
{{name}}: моделей {{models_count}}, в производстве {{in_production_count}}, модификаций {{modifications_count}}, средняя мощность {{avg_power}} л.с.
{{ENDROWS}}

АИС «Справочник российских автомобилей»');