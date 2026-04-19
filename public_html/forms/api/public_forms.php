<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_module.php';
forms_bootstrap();
json_response([
    'ok' => true,
    'forms' => forms_public_forms_payload(),
]);
