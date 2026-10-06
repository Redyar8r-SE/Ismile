<?php

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Auth;

Page::headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = Auth::user();
    try {
        Auth::checkCsrf();
    } catch (\Throwable) {
        Page::redirect('index.php');
    }
    if ($user) {
        Audit::log((int) $user['id'], 'logout', 'admin_user', (int) $user['id']);
    }
    Auth::logout();
}
Page::redirect('login.php');
