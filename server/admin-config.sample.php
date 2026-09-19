<?php
// Copy this file to admin-config.php on your hosting and fill it in.
// Keep admin-config.php out of Git: it holds the sign-in secrets.

return [

    // Any long random text. It signs the sign-in tickets, nothing else.
    // Changing it later only signs everybody out.
    'secret' => 'CHANGE-THIS-to-a-long-random-line-of-text',

    // Everyone who may sign in to the admin. Add or remove people here.
    // The password itself is never written down, only this hash of it.
    // Make a hash with node, putting your own password in place of the one
    // shown (the README has the same line):
    //
    //   node -e "const c=require('crypto'),s=c.randomBytes(16);console.log('pbkdf2$150000$'+s.toString('base64')+'$'+c.pbkdf2Sync('your-password',s,150000,32,'sha256').toString('base64'))"
    //
    'accounts' => [
        [
            'email' => 'you@example.com',
            'hash'  => 'pbkdf2$150000$PUT-THE-SALT-HERE$PUT-THE-HASH-HERE',
        ],
        // A second person, if you want one:
        // [
        //     'email' => 'helper@example.com',
        //     'hash'  => 'pbkdf2$150000$...$...',
        // ],
    ],

    // Shown in the admin's top bar. Optional.
    'name' => 'ismile.krd',

    // Where the website files are. The default is the folder above this one,
    // which is right when server/ sits inside the website folder. Set it only
    // if your hosting puts things somewhere else, for example:
    // 'site_root' => '/home/USER/public_html',
];
