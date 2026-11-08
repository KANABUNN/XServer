<?php

declare(strict_types=1);

/**
 * 部員名簿テーブル定義（fitsc_forms）。
 *
 * apps/roster_core/migrations/001_roster_tables.sql はこの定義から
 * `php tools/roster_migration_sql.php` で生成する。既存テーブルには一切手を入れない。
 */
function roster_schema_statements(): array
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'roster_group_directory' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_group_directory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(32) NOT NULL,
    group_name VARCHAR(255) NOT NULL,
    group_rank VARCHAR(50) DEFAULT NULL,
    activity_status VARCHAR(50) DEFAULT NULL,
    group_email VARCHAR(255) DEFAULT NULL,
    kintone_record_id BIGINT UNSIGNED DEFAULT NULL,
    kintone_revision BIGINT UNSIGNED DEFAULT NULL,
    is_present TINYINT(1) NOT NULL DEFAULT 1,
    refreshed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_group_directory_code (group_code)
) {$engine}
SQL,
        'roster_cases' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id BIGINT UNSIGNED NOT NULL,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    group_code VARCHAR(32) NOT NULL,
    group_name VARCHAR(255) NOT NULL DEFAULT '',
    review_state VARCHAR(16) NOT NULL DEFAULT 'draft',
    apply_state VARCHAR(16) NOT NULL DEFAULT 'not_applicable',
    draft_json LONGTEXT DEFAULT NULL,
    draft_base_json LONGTEXT DEFAULT NULL,
    draft_version INT UNSIGNED NOT NULL DEFAULT 0,
    draft_updated_at DATETIME DEFAULT NULL,
    latest_revision_id BIGINT UNSIGNED DEFAULT NULL,
    latest_revision_number INT UNSIGNED NOT NULL DEFAULT 0,
    current_acceptance_id BIGINT UNSIGNED DEFAULT NULL,
    current_job_id BIGINT UNSIGNED DEFAULT NULL,
    last_applied_job_id BIGINT UNSIGNED DEFAULT NULL,
    last_applied_revision_number INT UNSIGNED DEFAULT NULL,
    last_applied_at DATETIME DEFAULT NULL,
    last_submitted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_cases_unit (form_id, fiscal_year, group_code),
    KEY idx_roster_cases_states (review_state, apply_state, updated_at),
    KEY idx_roster_cases_group (group_code, fiscal_year),
    CONSTRAINT fk_roster_cases_form FOREIGN KEY (form_id) REFERENCES managed_forms (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_revisions' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    form_id BIGINT UNSIGNED NOT NULL,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    group_code VARCHAR(32) NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    review_state VARCHAR(16) NOT NULL DEFAULT 'submitted',
    submitter_name VARCHAR(100) NOT NULL,
    verified_email VARCHAR(255) NOT NULL DEFAULT '',
    verification_id BIGINT UNSIGNED DEFAULT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    base_json LONGTEXT DEFAULT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    active_count INT UNSIGNED NOT NULL DEFAULT 0,
    inactive_count INT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at DATETIME NOT NULL,
    submitted_ip VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_revisions_case_number (case_id, revision_number),
    KEY idx_roster_revisions_form_submitted (form_id, submitted_at),
    CONSTRAINT fk_roster_revisions_case FOREIGN KEY (case_id) REFERENCES roster_cases (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_reviews' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(32) NOT NULL,
    reason TEXT DEFAULT NULL,
    actor_type VARCHAR(16) NOT NULL DEFAULT 'staff',
    actor_account_id BIGINT UNSIGNED DEFAULT NULL,
    actor_login_id VARCHAR(100) DEFAULT NULL,
    actor_name VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_roster_reviews_case (case_id, id),
    KEY idx_roster_reviews_revision (revision_id, id),
    CONSTRAINT fk_roster_reviews_case FOREIGN KEY (case_id) REFERENCES roster_cases (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_acceptances' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_acceptances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    active_revision_id BIGINT UNSIGNED DEFAULT NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    compared_record_count INT UNSIGNED NOT NULL DEFAULT 0,
    compared_record_id BIGINT UNSIGNED DEFAULT NULL,
    compared_revision BIGINT UNSIGNED DEFAULT NULL,
    compared_rows_json LONGTEXT DEFAULT NULL,
    compared_at DATETIME NOT NULL,
    accepted_by_account_id BIGINT UNSIGNED DEFAULT NULL,
    accepted_by_login_id VARCHAR(100) DEFAULT NULL,
    accepted_by_name VARCHAR(190) DEFAULT NULL,
    accepted_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_by_name VARCHAR(190) DEFAULT NULL,
    revoke_reason VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_acceptances_active (active_revision_id),
    KEY idx_roster_acceptances_case (case_id, id),
    KEY idx_roster_acceptances_revision (revision_id),
    CONSTRAINT fk_roster_acceptances_case FOREIGN KEY (case_id) REFERENCES roster_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_roster_acceptances_revision FOREIGN KEY (revision_id) REFERENCES roster_revisions (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_apply_jobs' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_apply_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    acceptance_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    mode VARCHAR(16) DEFAULT NULL,
    phase VARCHAR(24) DEFAULT NULL,
    write_kind VARCHAR(8) DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL,
    lease_token CHAR(32) DEFAULT NULL,
    lease_expires_at DATETIME DEFAULT NULL,
    locked_by VARCHAR(120) DEFAULT NULL,
    target_record_id BIGINT UNSIGNED DEFAULT NULL,
    before_revision BIGINT UNSIGNED DEFAULT NULL,
    after_revision BIGINT UNSIGNED DEFAULT NULL,
    error_class VARCHAR(32) DEFAULT NULL,
    error_code VARCHAR(64) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    invalidated_at DATETIME DEFAULT NULL,
    invalidated_reason VARCHAR(64) DEFAULT NULL,
    applied_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_apply_jobs_acceptance (acceptance_id),
    KEY idx_roster_apply_jobs_pick (status, next_run_at, id),
    KEY idx_roster_apply_jobs_case (case_id, id),
    CONSTRAINT fk_roster_apply_jobs_case FOREIGN KEY (case_id) REFERENCES roster_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_roster_apply_jobs_acceptance FOREIGN KEY (acceptance_id) REFERENCES roster_acceptances (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_apply_attempts' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_apply_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME DEFAULT NULL,
    outcome VARCHAR(24) DEFAULT NULL,
    phase VARCHAR(24) DEFAULT NULL,
    http_status SMALLINT UNSIGNED DEFAULT NULL,
    kintone_code VARCHAR(64) DEFAULT NULL,
    error_class VARCHAR(32) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    detail_json LONGTEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_roster_apply_attempts_job (job_id, id),
    CONSTRAINT fk_roster_apply_attempts_job FOREIGN KEY (job_id) REFERENCES roster_apply_jobs (id) ON DELETE RESTRICT
) {$engine}
SQL,
        'roster_row_links' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_row_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(32) NOT NULL,
    student_id VARCHAR(32) NOT NULL,
    kintone_record_id BIGINT UNSIGNED NOT NULL,
    kintone_row_id BIGINT UNSIGNED NOT NULL,
    last_job_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_row_links_member (group_code, student_id),
    KEY idx_roster_row_links_record (kintone_record_id, kintone_row_id)
) {$engine}
SQL,
        'roster_group_verifications' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_group_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id BIGINT UNSIGNED NOT NULL,
    group_code VARCHAR(32) NOT NULL,
    email_masked VARCHAR(255) NOT NULL DEFAULT '',
    code_salt CHAR(32) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    session_ref CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    request_ip_hash CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_roster_verifications_group (group_code, created_at),
    KEY idx_roster_verifications_ip (request_ip_hash, created_at)
) {$engine}
SQL,
        'roster_worker_heartbeats' => <<<SQL
CREATE TABLE IF NOT EXISTS roster_worker_heartbeats (
    worker_key VARCHAR(64) NOT NULL,
    last_started_at DATETIME DEFAULT NULL,
    last_finished_at DATETIME DEFAULT NULL,
    last_status VARCHAR(16) DEFAULT NULL,
    last_summary TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_key)
) {$engine}
SQL,
    ];
}

function roster_ensure_schema(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    foreach (roster_schema_statements() as $sql) {
        $pdo->exec($sql);
    }
    $done[$key] = true;
}
