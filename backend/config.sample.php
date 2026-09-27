<?php
// iSmile backend settings.
//
// Copy this file to config.php on the server and fill it in. config.php holds
// passwords and keys: it is listed in .gitignore and must never be committed,
// emailed or pasted into a chat. The backend folder itself sits OUTSIDE
// public_html, so none of these files has a web address.

return [

    // 'test' on test.ismile.krd, 'live' on ismile.krd. The admin shows a wide
    // orange bar in test so nobody confuses the two.
    'env' => 'test',

    // The public address of this copy of the site, without a slash at the end.
    'site_url' => 'https://test.ismile.krd',

    // The website folder (the one holding index.html and data/).
    'site_root' => '/home/USER/public_html',

    // Private folder for student ID photos, backups and logs. Must NOT be
    // inside public_html. Created automatically if missing.
    'storage' => __DIR__ . '/storage',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'USER_ismile',
        'user' => 'USER_ismile',
        'pass' => 'CHANGE-ME',
    ],

    // Any long random text (64+ characters). It signs the QR codes on tickets
    // and the links in emails. Changing it later makes old tickets unreadable,
    // so set it once. Make one with: php -r "echo bin2hex(random_bytes(32));"
    'secret' => 'CHANGE-THIS-to-a-long-random-line-of-text',

    'timezone' => 'Asia/Baghdad',

    // ---- Payments ----
    // 'fake'   : a pretend payment page on our own site, for building and testing
    //            before Psoola sends its documents. Never use on the live site.
    // 'psoola' : the real Psoola API (filled in when their documents arrive).
    'payments' => [
        'gateway' => 'fake',
        'psoola'  => [
            'api_base'       => '',   // from Psoola's documentation
            'api_key'        => '',   // test key on test.ismile.krd, live key on ismile.krd
            'merchant_id'    => '',
            'webhook_secret' => '',
        ],
        // Used only by the fake gateway to sign its pretend webhooks.
        'fake_secret' => 'local-fake-gateway-secret',
    ],

    // ---- Email ----
    // 'log'   : emails are written to storage/outbox as files (for testing).
    // 'brevo' : sent through Brevo (https://www.brevo.com), from tickets@ismile.krd.
    'mail' => [
        'driver'     => 'log',
        'brevo_key'  => '',
        'from_email' => 'tickets@ismile.krd',
        'from_name'  => 'iSmile 2026',
        'reply_to'   => 'info@ismile.krd',
    ],

    // Who gets the "something needs attention" emails.
    'alerts_to' => ['info@ismile.krd'],

    // Shown at the bottom of every email.
    'office_phone' => '',
];
