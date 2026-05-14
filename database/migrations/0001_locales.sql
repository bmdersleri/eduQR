-- Migration 0001: locales table + seed rows
-- Phase 1 — i18n Foundation

CREATE TABLE IF NOT EXISTS `locales` (
    `code`          VARCHAR(8)  NOT NULL,
    `label_native`  VARCHAR(40) NOT NULL,
    `label_english` VARCHAR(40) NOT NULL,
    `is_rtl`        TINYINT(1)  NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`    INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `locales` (`code`, `label_native`, `label_english`, `is_rtl`, `is_active`, `sort_order`) VALUES
    ('en', 'English', 'English', 0, 1, 1),
    ('tr', 'Türkçe',  'Turkish', 0, 1, 2);
