<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/response_limit.php';
require_post();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$identifier = trim((string)($data['identifier'] ?? $data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_response(['ok' => false, 'message' => 'ログインIDまたはメールアドレスとパスワードを入力してください。'], 422);
}

try {
    rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../../apps/rate_limit_lend_login.json', 5, 300);
} catch (Throwable $e) {
    error_log('[lend login rate_limit] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => '短時間にログイン試行が多すぎます。時間をおいて再試行してください。'], 429);
}

if (!login_user($identifier, $password)) {
    json_response(['ok' => false, 'message' => 'ログインに失敗しました。ログインID・メールアドレス・パスワードを確認してください。'], 401);
}

json_response([
    'ok' => true,
    'message' => 'ログインしました。',
    'redirect' => current_user()['role'] === 'admin' ? 'admin.php' : 'user_dashboard.php',
]);
