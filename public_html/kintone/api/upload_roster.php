<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/upload_helpers.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/roster_csv_parser.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/diff_service.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/audit.php';
$user = kintone_auth_require_operator_access();
kintone_auth_require_csrf();
$ref = kintone_reference_id('UPL');
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 30 * 1024 * 1024) {
        throw new InvalidArgumentException('アップロードサイズが大きすぎます。');
    }
    $file = $_FILES['roster_csv'] ?? null;
    if (!is_array($file)) {
        throw new InvalidArgumentException('部員名簿CSVが選択されていません。');
    }
    $stored = kintone_store_roster_upload($file);
    $parsed = kintone_parse_roster_csv((string)$stored['full_path']);
    $pdo = kintone_pdo('org');
    $pdo->beginTransaction();
    $batchKey = 'ros_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO roster_import_batches (batch_key, original_name, stored_name, relative_path, file_size, mime_type, encoding, sha256_hash, status, total_rows, valid_rows, warning_rows, error_rows, created_by_account_id, created_by_login_id, summary_json) VALUES (:batch_key,:original_name,:stored_name,:relative_path,:file_size,:mime_type,:encoding,:sha256_hash,"parsed",:total_rows,:valid_rows,:warning_rows,:error_rows,:created_by_account_id,:created_by_login_id,:summary_json)');
    $stmt->execute([
        ':batch_key' => $batchKey,
        ':original_name' => $stored['original_name'],
        ':stored_name' => $stored['stored_name'],
        ':relative_path' => $stored['relative_path'],
        ':file_size' => $stored['file_size'],
        ':mime_type' => $stored['mime_type'],
        ':encoding' => $parsed['encoding'],
        ':sha256_hash' => $stored['sha256_hash'],
        ':total_rows' => $parsed['total_rows'],
        ':valid_rows' => count($parsed['rows']),
        ':warning_rows' => count($parsed['errors']),
        ':error_rows' => 0,
        ':created_by_account_id' => (int)($user['id'] ?? 0) ?: null,
        ':created_by_login_id' => (string)($user['login_id'] ?? ''),
        ':summary_json' => kintone_json_encode(['parse_errors' => array_slice($parsed['errors'], 0, 100)]),
    ]);
    $batchId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO organization_members (organization_code, organization_name, normalized_organization_name, category, activity_hint, import_batch_id, member_identifier, member_name, member_email, role_name, role_rank, member_status, is_representative_candidate, raw_json) VALUES (:organization_code,:organization_name,:normalized_organization_name,:category,:activity_hint,:import_batch_id,:member_identifier,:member_name,:member_email,:role_name,:role_rank,:member_status,:is_representative_candidate,NULL)');
    foreach ($parsed['rows'] as $row) {
        $ins->execute([
            ':organization_code' => $row['organization_code'],
            ':organization_name' => $row['organization_name'],
            ':normalized_organization_name' => $row['normalized_organization_name'],
            ':category' => $row['category'],
            ':activity_hint' => $row['activity_hint'],
            ':import_batch_id' => $batchId,
            ':member_identifier' => $row['member_identifier'],
            ':member_name' => $row['member_name'],
            ':member_email' => $row['member_email'],
            ':role_name' => $row['role_name'],
            ':role_rank' => (int)$row['role_rank'],
            ':member_status' => $row['member_status'],
            ':is_representative_candidate' => (int)$row['is_representative_candidate'],
        ]);
    }
    $pdo->commit();
    $summary = kintone_diff_generate_for_batch($batchId);
    kintone_write_audit_log('kintone.roster.upload', 'roster_import_batch', (string)$batchId, [
        'filename' => $stored['original_name'],
        'sha256' => $stored['sha256_hash'],
        'rows' => count($parsed['rows']),
        'warnings' => count($parsed['errors']),
        'diff' => $summary,
    ], $user);
    kintone_set_flash('success', '名簿CSVを解析し、差分を作成しました。');
    header('Location: ../diff.php?batch_id=' . $batchId, true, 302);
    exit;
} catch (InvalidArgumentException $e) {
    kintone_set_flash('error', $e->getMessage());
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kintone upload PDO][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '名簿の保存中にエラーが発生しました（参照ID: ' . $ref . '）。');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kintone upload][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '名簿の解析中にエラーが発生しました（参照ID: ' . $ref . '）。');
}
header('Location: ../upload.php', true, 302);
exit;
