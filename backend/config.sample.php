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

    // Optional final certificate artwork: absolute path to an approved PNG/JPEG.
    // Leave empty to use the built-in landscape A4 design. Name is centered
    // at y=98 mm; certificate number and attended days at y=181 mm.
    'certificates' => ['background' => ''],

    // ---- Payments ----
    // 'fake'   : a pretend payment page on our own site, for building and testing
    //            before Psoola sends its documents. Never use on the live site.
    // 'psoola' : PSOOLA's sales-partner API. PSOOLA's checkout page takes the
    //            money and PSOOLA issues and emails the ticket itself; iSmile
    //            keeps the registration but sends no ticket email of its own.
    'payments' => [
        'gateway' => 'fake',
        'psoola'  => [
            // PSOOLA's partner API address, without /api/... and without a
            // slash at the end: https://actions.psoola.com (live),
            // https://stage-actions.psoola.com (PSOOLA staging),
            // http://127.0.0.1:3211 (local PSOOLA).
            'api_base'       => '',
            // The partner API key PSOOLA gives you: psk_<env>_<keyId>_<secret>.
            // Test key on test.ismile.krd, live key on ismile.krd.
            'api_key'        => '',
            'merchant_id'    => '',   // not used by the v1 partner API
            // Signs PSOOLA's webhooks (X-Psoola-Signature). Give PSOOLA the
            // webhook address https://<this site>/api/webhook.php.
            'webhook_secret' => '',
            // The PSOOLA event and show iSmile 2026 is sold as.
            'event_id'       => '',
            'show_id'        => '',
            // PSOOLA ticket type ids, one per iSmile ticket. PSOOLA is the
            // price authority (all IQD): what a visitor pays is the total
            // PSOOLA returns for the session, and with this gateway the
            // registration page shows PSOOLA's prices (api/psoola-prices.php,
            // cached 5 minutes) instead of data/tickets.json.
            'ticket_types'   => [
                'professional' => '',
                'student'      => '',
                'vip'          => '',
            ],
            // PSOOLA add-on ids for the lunch days (bought once per ticket).
            // A VIP's included lunch day is never sent; only an extra paid
            // day is, because PSOOLA cannot yet express "one of two free".
            'add_ons'        => [
                'lunchDay1' => '',
                'lunchDay2' => '',
            ],
        ],
        // Used only by the fake gateway to sign its pretend webhooks.
        'fake_secret' => 'local-fake-gateway-secret',
    ],

    // ---- Email ----
    // 'log'   : emails are written to storage/outbox as files (for testing).
    // 'brevo' : sent through Brevo (https://www.brevo.com), from tickets@ismile.krd.
    // 'resend': sent through Resend (https://resend.com), from tickets@ismile.krd
    //           (the domain must be verified in Resend first).
    'mail' => [
        'driver'     => 'log',
        'brevo_key'  => '',
        'resend_key' => '',
        'from_email' => 'tickets@ismile.krd',
        'from_name'  => 'iSmile 2026',
        'reply_to'   => 'ismile@italk.krd',
    ],

    // Who gets the "something needs attention" emails.
    'alerts_to' => ['ismile@italk.krd'],

    // Shown at the bottom of every email.
    'office_phone' => '',
];
