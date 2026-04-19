<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_post();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(['ok' => false, 'message' => 'メールアドレスとパスワードを入力してください。'], 422);
}

if (!login_user($email, $password)) {
    json_response(['ok' => false, 'message' => 'ログインに失敗しました。'], 401);
}

json_response([
    'ok' => true,
    'message' => 'ログインしました。',
    'redirect' => current_user()['role'] === 'admin' ? 'admin.php' : 'user_dashboard.php',
]);
