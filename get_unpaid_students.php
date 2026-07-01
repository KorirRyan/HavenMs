<?php
require_once 'finance_api_common.php';

finance_json_success([
    'students' => finance_fetch_unpaid_students($conn),
]);
