<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function backup_install_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_jobs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  job_key varchar(120) NOT NULL,
  job_type enum('daily','weekly','monthly','verify','cleanup','manual') NOT NULL DEFAULT 'daily',
  trigger_type enum('cron','manual','system') NOT NULL DEFAULT 'cron',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  status enum('running','success','partial','failed','warning') NOT NULL DEFAULT 'running',
  message varchar(1000) DEFAULT NULL,
  backup_root varchar(500) DEFAULT NULL,
  total_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  success_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  failed_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  total_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  manifest_path varchar(500) DEFAULT NULL,
  manifest_sha256 char(64) DEFAULT NULL,
  host_name varchar(190) DEFAULT NULL,
  php_version varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_backup_jobs_job_key (job_key),
  KEY idx_backup_jobs_type_started (job_type, started_at),
  KEY idx_backup_jobs_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_items (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id bigint(20) UNSIGNED NOT NULL,
  item_type enum('database','file','manifest','verify','cleanup') NOT NULL,
  target_key varchar(120) NOT NULL,
  target_label varchar(190) DEFAULT NULL,
  source_path varchar(500) DEFAULT NULL,
  backup_path varchar(500) DEFAULT NULL,
  file_count int(10) UNSIGNED DEFAULT NULL,
  total_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  sha256 char(64) DEFAULT NULL,
  status enum('success','failed','warning','skipped') NOT NULL DEFAULT 'success',
  error_message varchar(1000) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_backup_items_job (job_id, id),
  KEY idx_backup_items_type_target (item_type, target_key),
  KEY idx_backup_items_status (status),
  CONSTRAINT fk_backup_items_job FOREIGN KEY (job_id) REFERENCES backup_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_alerts (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id bigint(20) UNSIGNED DEFAULT NULL,
  level enum('info','warning','error','critical') NOT NULL DEFAULT 'warning',
  alert_key varchar(120) NOT NULL,
  message varchar(1000) NOT NULL,
  is_resolved tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_backup_alerts_unresolved (is_resolved, level, created_at),
  KEY idx_backup_alerts_job (job_id),
  CONSTRAINT fk_backup_alerts_job FOREIGN KEY (job_id) REFERENCES backup_jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);



    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_storage_snapshots (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  collected_at datetime NOT NULL,
  status enum('success','warning','failed') NOT NULL DEFAULT 'success',
  source varchar(120) NOT NULL,
  total_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  forms_live_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  forms_archive_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  switchbot_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  report_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  tmp_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  backup_root_bytes bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  payload_json longtext DEFAULT NULL,
  cleanup_log_path varchar(500) DEFAULT NULL,
  cleanup_log_mtime datetime DEFAULT NULL,
  cleanup_log_tail mediumtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_backup_storage_snapshots_collected (collected_at),
  KEY idx_backup_storage_snapshots_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_reports (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  report_key varchar(120) NOT NULL,
  report_type enum('monthly','manual') NOT NULL DEFAULT 'monthly',
  generated_at datetime NOT NULL,
  report_path varchar(500) NOT NULL,
  report_sha256 char(64) DEFAULT NULL,
  status enum('success','warning','failed') NOT NULL DEFAULT 'success',
  summary_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_backup_reports_report_key (report_key),
  KEY idx_backup_reports_generated (generated_at),
  KEY idx_backup_reports_type (report_type, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);



    // Stage 3 additions: integrity checks, operator audit log, and richer alerts.
    foreach ([
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS detail_json LONGTEXT DEFAULT NULL AFTER message",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS first_seen_at DATETIME DEFAULT NULL AFTER detail_json",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS last_seen_at DATETIME DEFAULT NULL AFTER first_seen_at",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS occurrence_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER last_seen_at",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS resolved_by_account_id BIGINT UNSIGNED DEFAULT NULL AFTER resolved_at",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS resolved_by_login_id VARCHAR(100) DEFAULT NULL AFTER resolved_by_account_id",
        "ALTER TABLE backup_alerts ADD COLUMN IF NOT EXISTS resolve_note VARCHAR(1000) DEFAULT NULL AFTER resolved_by_login_id",
        "ALTER TABLE backup_alerts ADD KEY IF NOT EXISTS idx_backup_alerts_key_resolved (alert_key, is_resolved)",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Older MySQL/MariaDB variants may not support IF NOT EXISTS for ALTER.
            // The stage SQL file performs the same migration for normal installs.
        }
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_integrity_checks (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  check_key varchar(120) NOT NULL,
  trigger_type enum('cron','manual','system') NOT NULL DEFAULT 'cron',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  status enum('running','success','warning','failed') NOT NULL DEFAULT 'running',
  checked_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  ok_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  missing_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  warning_items int(10) UNSIGNED NOT NULL DEFAULT 0,
  summary_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_backup_integrity_checks_key (check_key),
  KEY idx_backup_integrity_checks_started (started_at),
  KEY idx_backup_integrity_checks_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_integrity_items (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  check_id bigint(20) UNSIGNED NOT NULL,
  app_key varchar(64) NOT NULL,
  source_table varchar(120) NOT NULL,
  source_id varchar(191) DEFAULT NULL,
  relative_path varchar(500) DEFAULT NULL,
  resolved_path varchar(500) DEFAULT NULL,
  item_status enum('ok','missing','warning','skipped') NOT NULL DEFAULT 'ok',
  message varchar(1000) DEFAULT NULL,
  detail_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_backup_integrity_items_check (check_id, item_status),
  KEY idx_backup_integrity_items_app (app_key, source_table),
  CONSTRAINT fk_backup_integrity_items_check FOREIGN KEY (check_id) REFERENCES backup_integrity_checks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_operation_logs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_account_id bigint(20) UNSIGNED DEFAULT NULL,
  actor_login_id varchar(100) DEFAULT NULL,
  actor_display_name varchar(100) DEFAULT NULL,
  action varchar(100) NOT NULL,
  target_type varchar(100) DEFAULT NULL,
  target_id varchar(191) DEFAULT NULL,
  detail_json longtext DEFAULT NULL,
  ip_address varchar(64) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_backup_operation_logs_created (created_at),
  KEY idx_backup_operation_logs_actor (actor_account_id),
  KEY idx_backup_operation_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS backup_settings (
  setting_key varchar(120) NOT NULL,
  setting_value longtext DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
}
