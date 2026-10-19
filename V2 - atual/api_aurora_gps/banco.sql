-- =====================================================================
--  AURORA GPS - SCHEMA DO BANCO DE DADOS
-- =====================================================================
--  Engine: MySQL 8.0+ / MariaDB 10.3+
--  Descrição: Estrutura relacional para armazenamento de metadados dos
--             rastreadores ESP32 e histórico de leituras de telemetria/SOS.
-- =====================================================================

-- ---------------------------------------------------------------------
-- BLOCO 1: CRIAÇÃO E SELEÇÃO DA BASE DE DADOS
-- ---------------------------------------------------------------------
-- Cria o schema caso não exista, utilizando utf8mb4 para suporte total a 
-- caracteres especiais e acentuação.
CREATE DATABASE IF NOT EXISTS `api_aurora_gps`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `api_aurora_gps`;

-- ---------------------------------------------------------------------
-- BLOCO 2: TABELA DE DISPOSITIVOS (`dispositivos`)
-- ---------------------------------------------------------------------
-- Armazena o cadastro e o último estado conhecido de cada hardware ESP32.
-- O 'device_uid' é a chave de negócio única (MAC Address do chip ESP32).
CREATE TABLE IF NOT EXISTS `dispositivos` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Chave primária interna relacional',
    `device_uid`     VARCHAR(64)  NOT NULL COMMENT 'Identificador único do hardware (MAC do ESP32)',
    `nome`           VARCHAR(120)     NULL COMMENT 'Nome amigável do rastreador',
    `modelo`         VARCHAR(60)      NULL COMMENT 'Ex.: ESP32 + NEO-6M',
    `firmware`       VARCHAR(30)      NULL COMMENT 'Versão do firmware compilado',
    `bateria_atual`  INT              NULL COMMENT 'Última porcentagem de bateria reportada (0-100)',
    `ativo`          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = Ativo, 0 = Bloqueado/Inativo',
    `ultimo_contato` DATETIME         NULL COMMENT 'Timestamp do último pacote recebido',
    `criado_em`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data do primeiro registro no sistema',
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dispositivos_device_uid` (`device_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BLOCO 3: TABELA DE LOCALIZAÇÕES E TELEMETRIA (`localizacoes`)
-- ---------------------------------------------------------------------
-- Registra o histórico temporal de coordenadas, sensores, bateria e eventos de SOS.
CREATE TABLE IF NOT EXISTS `localizacoes` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Chave primária incremental de leituras',
    `dispositivo_id` INT UNSIGNED    NOT NULL COMMENT 'Chave estrangeira vinculada à tabela dispositivos',
    `latitude`       DECIMAL(10,7)   NOT NULL COMMENT 'Graus decimais (-90.0000000 a 90.0000000)',
    `longitude`      DECIMAL(11,7)   NOT NULL COMMENT 'Graus decimais (-180.0000000 a 180.0000000)',
    `altitude_m`     DECIMAL(8,2)        NULL COMMENT 'Altitude em metros acima do nível do mar',
    `velocidade_kmh` DECIMAL(6,2)        NULL COMMENT 'Velocidade calculada pelo GPS em km/h',
    `satelites`      TINYINT UNSIGNED    NULL COMMENT 'Quantidade de satélites sincronizados',
    `bateria`        INT                 NULL COMMENT 'Porcentagem da bateria no momento exato do registro (0-100)',
    `sos`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0 = Leitura de rotina, 1 = Alerta SOS acionado',
    `data_hora_gps`  DATETIME        NOT NULL COMMENT 'Timestamp do GPS ajustado com fuso horário',
    `recebido_em`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp da chegada no servidor Apache',
    `ip_origem`      VARCHAR(45)         NULL COMMENT 'IP público/privado que realizou o HTTP POST',
    
    PRIMARY KEY (`id`),
    
    -- Restrição de unicidade para evitar duplicidade em reenvios de pacotes pela rede
    UNIQUE KEY `uk_localizacoes_ponto` (`dispositivo_id`, `data_hora_gps`),
    
    -- Índice composto para otimizar consultas do aplicativo filtradas por data/hora descendente
    KEY `idx_localizacoes_consulta` (`dispositivo_id`, `data_hora_gps` DESC),
    
    -- Chave estrangeira com exclusão e atualização em cascata
    CONSTRAINT `fk_localizacoes_dispositivo`
        FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;