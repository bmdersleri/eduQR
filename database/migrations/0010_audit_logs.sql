-- Migration: 0010_audit_logs.sql
-- Phase 10 — Security Hardening (T-1000)
-- Creates the audit_logs table (FR-90).
-- Safe to re-run; uses IF NOT EXISTS guard.

CREATE TABLE IF NOT EXISTS audit_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type    ENUM('instructor','admin','system') NOT NULL,
    actor_id      BIGINT UNSIGNED NULL,
    action        VARCHAR(80)  NOT NULL,
    entity_type   VARCHAR(40)  NULL,
    entity_id     BIGINT UNSIGNED NULL,
    metadata_json JSON         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_action (action, created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
