<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
json_response([
    'ok' => true,
    'forms' => forms_public_forms_payload(),
]);
