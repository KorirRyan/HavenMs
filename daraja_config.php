<?php

return [
    // Use sandbox for development. Switch to https://api.safaricom.co.ke for production.
    'base_url' => 'https://sandbox.safaricom.co.ke',

    // Fill these with your Daraja app credentials.
    'consumer_key' => 'LYWOZnrVkYNqAwaE6p2USdyHL5vDzIeQYNyGGDEOSGLgIEEr',
    'consumer_secret' => 'hYBhOc5sCA11huXQolIAuYm7O6rkwZdXEddvrm0pHqR51syTGwqkCKtR3WZBvgPn',
    'shortcode' => '174379',
    'passkey' => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',

    // This must be a public URL that Safaricom can reach.
    'callback_url' => 'https://lecithal-kendal-nonpictorially.ngrok-free.dev/havenms/daraja_callback.php',

    // CustomerPayBillOnline is the common option for paybill integrations.
    'transaction_type' => 'CustomerPayBillOnline',

    // Hostel fee charged to a student once the room booking is approved.
    'hostel_fee' => 15000,
];
