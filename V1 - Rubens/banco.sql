-- =====================================================================
--  API Arduino / ESP32 - Rastreamento GPS
--  Banco de dados MySQL 8.0+
--
--  Para criar:  mysql -u root -p < banco.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `api_arduino`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `api_arduino`;

-- ---------------------------------------------------------------------
-- Identificacao do projeto/dispositivo Arduino
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispositivos` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_uid`     VARCHAR(64)  NOT NULL COMMENT 'Identificador unico do hardware (MAC do ESP32)',
    `nome`           VARCHAR(120)     NULL COMMENT 'Nome amigavel do projeto/rastreador',
    `modelo`         VARCHAR(60)      NULL COMMENT 'Ex.: ESP32 + NEO-6M',
    `firmware`       VARCHAR(30)      NULL,
    `ativo`          TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_contato` DATETIME         NULL,
    `criado_em`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dispositivos_device_uid` (`device_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Leituras de localizacao enviadas pelo dispositivo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `localizacoes` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dispositivo_id` INT UNSIGNED    NOT NULL,
    `latitude`       DECIMAL(10,7)   NOT NULL COMMENT 'Graus decimais: -90 a 90',
    `longitude`      DECIMAL(11,7)   NOT NULL COMMENT 'Graus decimais: -180 a 180',
    `altitude_m`     DECIMAL(8,2)        NULL,
    `velocidade_kmh` DECIMAL(6,2)        NULL,
    `satelites`      TINYINT UNSIGNED    NULL,
    `data_hora_gps`  DATETIME        NOT NULL COMMENT 'Data/hora do GPS ja com o fuso do dispositivo',
    `recebido_em`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_origem`      VARCHAR(45)         NULL,
    PRIMARY KEY (`id`),
    -- Evita duplicar o mesmo ponto quando o ESP32 reenvia apos falha de rede
    UNIQUE KEY `uk_localizacoes_ponto` (`dispositivo_id`, `data_hora_gps`),
    KEY `idx_localizacoes_consulta` (`dispositivo_id`, `data_hora_gps` DESC),
    CONSTRAINT `fk_localizacoes_dispositivo`
        FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
