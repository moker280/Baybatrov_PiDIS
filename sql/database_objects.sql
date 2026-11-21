-- =====================================================================
-- АИС «СРА». Файл 2 из 2: ровно 3 триггера + 3 функции + 3 процедуры.
-- Целевая СУБД: MariaDB 10.5+ (SIGNAL, CHECK, несколько триггеров на таблицу).
-- DEFINER не задаётся; SUPER не требуется; глобальные настройки не меняются.
-- Импорт: phpMyAdmin (вкладка SQL понимает DELIMITER) после schema_and_data.sql.
-- Функции/процедуры используются приложением: sp_search_cars — главная страница,
-- sp_manufacturer_report — отчёт PDF и API, sp_lock_user — блокировки,
-- fn_manufacturer_models_count — списки/API и отчёт, fn_model_production_years —
-- карточки/списки, fn_is_password_expired — список пользователей админ-области.
-- =====================================================================

DELIMITER $$

-- ---------- Триггер 1: проверка годов и согласование статуса модели при вставке ----------
DROP TRIGGER IF EXISTS trg_car_models_before_insert $$
CREATE TRIGGER trg_car_models_before_insert
BEFORE INSERT ON car_models
FOR EACH ROW
BEGIN
    IF NEW.production_end_year IS NOT NULL
       AND NEW.production_end_year < NEW.production_start_year THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Год окончания выпуска меньше года начала';
    END IF;
    -- Если год окончания задан, модель не может считаться производящейся.
    IF NEW.production_end_year IS NOT NULL THEN
        SET NEW.status = 'discontinued';
    END IF;
END $$

-- ---------- Триггер 2: нормализация и проверка модификации при обновлении ----------
DROP TRIGGER IF EXISTS trg_modifications_before_update $$
CREATE TRIGGER trg_modifications_before_update
BEFORE UPDATE ON modifications
FOR EACH ROW
BEGIN
    -- У электродвигателя объём не хранится.
    IF NEW.engine_type = 'электро' THEN
        SET NEW.engine_volume_cm3 = NULL;
    END IF;
    IF NEW.year_end IS NOT NULL AND NEW.year_end < NEW.year_start THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Год окончания выпуска модификации меньше года начала';
    END IF;
    IF NEW.power_hp < 30 OR NEW.power_hp > 2000 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Мощность должна быть в диапазоне 30..2000 л.с.';
    END IF;
END $$

-- ---------- Триггер 3: запрет удаления администратора на уровне БД (ТЗ 3.2.7) ----------
DROP TRIGGER IF EXISTS trg_users_before_delete $$
CREATE TRIGGER trg_users_before_delete
BEFORE DELETE ON users
FOR EACH ROW
BEGIN
    IF OLD.role = 'admin' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Удаление учётной записи администратора запрещено';
    END IF;
END $$

-- ---------- Функция 1: количество моделей производителя ----------
DROP FUNCTION IF EXISTS fn_manufacturer_models_count $$
CREATE FUNCTION fn_manufacturer_models_count(p_manufacturer_id INT UNSIGNED)
RETURNS INT
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'Число моделей указанного производителя'
BEGIN
    DECLARE v_count INT;
    SELECT COUNT(*) INTO v_count FROM car_models WHERE manufacturer_id = p_manufacturer_id;
    RETURN v_count;
END $$

-- ---------- Функция 2: строка годов выпуска модели ----------
DROP FUNCTION IF EXISTS fn_model_production_years $$
CREATE FUNCTION fn_model_production_years(p_model_id INT UNSIGNED)
RETURNS VARCHAR(40)
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'Годы выпуска модели в виде "1967 — 2001" или "2015 — н.в."'
BEGIN
    DECLARE v_start SMALLINT;
    DECLARE v_end SMALLINT;
    SELECT production_start_year, production_end_year
        INTO v_start, v_end
        FROM car_models WHERE id = p_model_id;
    IF v_start IS NULL THEN
        RETURN '';
    END IF;
    RETURN CONCAT(v_start, ' — ', IFNULL(v_end, 'н.в.'));
END $$

-- ---------- Функция 3: истёк ли 90-дневный срок пароля (ТЗ 3.2.6) ----------
DROP FUNCTION IF EXISTS fn_is_password_expired $$
CREATE FUNCTION fn_is_password_expired(p_user_id INT UNSIGNED)
RETURNS TINYINT
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY INVOKER
COMMENT '1, если пароль старше 90 дней (соответствует security.password_days=90)'
BEGIN
    DECLARE v_changed DATETIME;
    SELECT last_password_change INTO v_changed FROM users WHERE id = p_user_id;
    IF v_changed IS NULL THEN
        RETURN 0;
    END IF;
    RETURN IF(v_changed < DATE_SUB(NOW(), INTERVAL 90 DAY), 1, 0);
END $$

-- ---------- Процедура 1: сводный отчёт по производителям ----------
DROP PROCEDURE IF EXISTS sp_manufacturer_report $$
CREATE PROCEDURE sp_manufacturer_report()
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'Отчёт: модели, выпуск, модификации, средняя мощность по каждому производителю'
BEGIN
    SELECT
        mf.id,
        mf.name,
        fn_manufacturer_models_count(mf.id) AS models_count,
        (SELECT COUNT(*) FROM car_models cm
          WHERE cm.manufacturer_id = mf.id AND cm.status = 'in_production') AS in_production_count,
        (SELECT COUNT(*) FROM modifications md
          JOIN car_models cm ON cm.id = md.car_model_id
          WHERE cm.manufacturer_id = mf.id) AS modifications_count,
        (SELECT ROUND(AVG(md.power_hp)) FROM modifications md
          JOIN car_models cm ON cm.id = md.car_model_id
          WHERE cm.manufacturer_id = mf.id) AS avg_power
    FROM manufacturers mf
    ORDER BY mf.name;
END $$

-- ---------- Процедура 2: подборка автомобилей по фильтрам ----------
DROP PROCEDURE IF EXISTS sp_search_cars $$
CREATE PROCEDURE sp_search_cars(
    IN p_q VARCHAR(100),
    IN p_manufacturer_id INT,
    IN p_body_type VARCHAR(30),
    IN p_status VARCHAR(20)
)
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'Подборка моделей: текст, производитель (0=все), кузов и статус (пусто=все)'
BEGIN
    SELECT
        cm.id,
        mf.name AS manufacturer_name,
        cm.name AS model_name,
        cm.body_type,
        fn_model_production_years(cm.id) AS production_years,
        cm.status,
        (SELECT COUNT(*) FROM modifications md WHERE md.car_model_id = cm.id) AS modifications_count
    FROM car_models cm
    JOIN manufacturers mf ON mf.id = cm.manufacturer_id
    WHERE (p_q = ''
           OR mf.name LIKE CONCAT('%', p_q, '%')
           OR cm.name LIKE CONCAT('%', p_q, '%')
           OR EXISTS (SELECT 1 FROM modifications md
                       WHERE md.car_model_id = cm.id AND md.code LIKE CONCAT('%', p_q, '%')))
      AND (p_manufacturer_id = 0 OR cm.manufacturer_id = p_manufacturer_id)
      AND (p_body_type = '' OR cm.body_type = p_body_type)
      AND (p_status = '' OR cm.status = p_status)
    ORDER BY mf.name, cm.name
    LIMIT 200;
END $$

-- ---------- Процедура 3: временная блокировка учётной записи ----------
DROP PROCEDURE IF EXISTS sp_lock_user $$
CREATE PROCEDURE sp_lock_user(IN p_user_id INT, IN p_seconds INT)
MODIFIES SQL DATA
SQL SECURITY INVOKER
COMMENT 'Блокировка на p_seconds секунд (используется при 3 неверных попытках и админом)'
BEGIN
    UPDATE users
    SET is_locked = 1,
        lock_until = DATE_ADD(NOW(), INTERVAL p_seconds SECOND)
    WHERE id = p_user_id;
END $$

DELIMITER ;

-- =====================================================================
-- ПРИМЕРЫ ВЫЗОВА И ПРОВЕРКИ (выполнять по одному в phpMyAdmin).
-- Предполагается, что schema_and_data.sql импортирован и seed_users.php выполнен.
--
-- Функции:
--   SELECT fn_manufacturer_models_count(1);            -- 4 (модели АВТОВАЗа)
--   SELECT fn_model_production_years(10);              -- '1967 — 2001'
--   SELECT fn_model_production_years(1);               -- '2015 — н.в.'
--   SELECT id, login, fn_is_password_expired(id) AS expired FROM users;
--
-- Процедуры:
--   CALL sp_manufacturer_report();                     -- строки отчёта по 6 производителям
--   CALL sp_search_cars('vesta', 0, '', '');           -- найдёт Lada Vesta (по коду модификации тоже)
--   CALL sp_search_cars('', 1, 'седан', 'in_production'); -- седаны АВТОВАЗа в производстве
--   CALL sp_lock_user(3, 60);                          -- блокировка пользователя id=3 на 60 с (демо)
--   SELECT id, login, is_locked, lock_until FROM users WHERE id = 3;
--   -- снять демо-блокировку:
--   UPDATE users SET is_locked = 0, lock_until = NULL, login_fail_count = 0, code_fail_count = 0 WHERE id = 3;
--
-- Триггеры (ожидаемые ошибки — это и есть демонстрация защиты):
--   -- trg_car_models_before_insert: ошибка 'Год окончания выпуска меньше года начала'
--   INSERT INTO car_models (manufacturer_id, name, body_type, production_start_year, production_end_year, status)
--   VALUES (1, 'Демо-ошибка', 'седан', 2000, 1990, 'discontinued');
--   -- trg_car_models_before_insert: статус автоматически станет 'discontinued'
--   INSERT INTO car_models (manufacturer_id, name, body_type, production_start_year, production_end_year, status)
--   VALUES (1, 'Демо-нормализация', 'седан', 2000, 2010, 'in_production');
--   SELECT name, status FROM car_models WHERE name = 'Демо-нормализация';
--   DELETE FROM car_models WHERE name = 'Демо-нормализация';  -- очистка демо
--   -- trg_modifications_before_update: электродвигателю объём обнуляется
--   UPDATE modifications SET engine_volume_cm3 = 1600 WHERE code = 'IPRO-EV';
--   SELECT code, engine_type, engine_volume_cm3 FROM modifications WHERE code = 'IPRO-EV'; -- NULL
--   -- trg_users_before_delete: ошибка 'Удаление учётной записи администратора запрещено'
--   DELETE FROM users WHERE login = 'admin';
-- =====================================================================