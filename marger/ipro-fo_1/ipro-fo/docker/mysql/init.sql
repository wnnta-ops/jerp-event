-- ──────────────────────────────────────────────────────────────────────────────
-- Java Core System — Bootstrap SQL untuk MariaDB container
-- ──────────────────────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS ipro_fo_testing
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON ipro_fo.* TO 'ipro'@'%';
GRANT ALL PRIVILEGES ON ipro_fo_testing.* TO 'ipro'@'%';
FLUSH PRIVILEGES;

SET GLOBAL log_bin_trust_function_creators = 1;
SET GLOBAL sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
