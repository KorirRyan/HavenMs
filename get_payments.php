<?php
require_once 'finance_api_common.php';

finance_json_success([
    'payments' => finance_fetch_payments($conn),
]);
