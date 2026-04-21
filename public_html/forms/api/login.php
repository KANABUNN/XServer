<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_post();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$identifier = trim((string)($data['email'] ?? $data['login_id'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($identifier === '' || $password === '') {
    json_response(['ok' => false, 'message' => 'ログインIDまたはメールアドレスとパスワードを入力してください。'], 422);
}
if (!login_user($identifier, $password)) {
    json_response(['ok' => false, 'message' => 'ログインに失敗しました。'], 401);
}
json_response([
    'ok' => true,
    'message' => 'ログインしました。',
    'redirect' => 'admin.php',
]);
