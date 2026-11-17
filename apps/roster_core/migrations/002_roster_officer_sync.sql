-- 部員名簿 第2段階（ID22 登録者・ID21 役員台帳への反映）の追加テーブル
-- 対象DB: fitsc_forms（001_roster_tables.sql を適用済みであること）
-- 既存データは書き換えない。CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS のため再実行できる（MariaDB 10.0.2 以降）。
-- 生成: php tools/roster_migration_sql.php 002 （apps/roster_core/schema.php が正）

SET NAMES utf8mb4;

-- roster_apply_steps
CREATE TABLE IF NOT EXISTS roster_apply_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(32) NOT NULL,
    step_order INT UNSIGNED NOT NULL,
    target_app VARCHAR(16) NOT NULL,
    target_app_id INT UNSIGNED DEFAULT NULL,
    operation VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    phase VARCHAR(24) DEFAULT NULL,
    write_kind VARCHAR(8) DEFAULT NULL,
    target_record_id BIGINT UNSIGNED DEFAULT NULL,
    before_revision BIGINT UNSIGNED DEFAULT NULL,
    after_revision BIGINT UNSIGNED DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    error_class VARCHAR(32) DEFAULT NULL,
    error_code VARCHAR(64) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_apply_steps_key (job_id, step_key),
    KEY idx_roster_apply_steps_order (job_id, step_order),
    CONSTRAINT fk_roster_apply_steps_job FOREIGN KEY (job_id) REFERENCES roster_apply_jobs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- roster_group_apply_guards
CREATE TABLE IF NOT EXISTS roster_group_apply_guards (
    tenant VARCHAR(190) NOT NULL,
    group_code VARCHAR(32) NOT NULL,
    officer_form_id BIGINT UNSIGNED DEFAULT NULL,
    officer_fiscal_year SMALLINT UNSIGNED DEFAULT NULL,
    generation INT UNSIGNED NOT NULL DEFAULT 0,
    active_job_id BIGINT UNSIGNED DEFAULT NULL,
    updated_by VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant, group_code),
    KEY idx_roster_group_apply_guards_form (officer_form_id, officer_fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- roster_officer_history
CREATE TABLE IF NOT EXISTS roster_officer_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant VARCHAR(190) NOT NULL,
    group_code VARCHAR(32) NOT NULL,
    event_key VARCHAR(100) NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    previous_student_id VARCHAR(32) DEFAULT NULL,
    previous_name VARCHAR(190) DEFAULT NULL,
    new_student_id VARCHAR(32) DEFAULT NULL,
    new_name VARCHAR(190) DEFAULT NULL,
    appointed_on DATE DEFAULT NULL,
    applied_at DATETIME DEFAULT NULL,
    acceptance_id BIGINT UNSIGNED DEFAULT NULL,
    job_id BIGINT UNSIGNED DEFAULT NULL,
    revision_id BIGINT UNSIGNED DEFAULT NULL,
    ledger_record_id BIGINT UNSIGNED DEFAULT NULL,
    recorded_by VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_officer_history_event (event_key),
    KEY idx_roster_officer_history_group (tenant, group_code, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- roster_apply_attempts.step_key
ALTER TABLE roster_apply_attempts ADD COLUMN IF NOT EXISTS step_key VARCHAR(32) DEFAULT NULL AFTER phase;

-- roster_apply_attempts.target_app_id
ALTER TABLE roster_apply_attempts ADD COLUMN IF NOT EXISTS target_app_id INT UNSIGNED DEFAULT NULL AFTER step_key;

