<?php
// The frame shared by every admin page: security headers, sign-in and role
// checks, the menu, messages after an action, and small display helpers.

declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\App;
use Ismile\Auth;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Security;
use Ismile\SiteData;
use Ismile\UserError;

final class Page
{
    public const MENU = [
        'index'         => ['Dashboard', 'dashboard'],
        'registrations' => ['Registrations', 'registrations'],
        'lists'         => ['Lists', 'registrations'],
        'payments'      => ['Payments', 'payments'],
        'workshops'     => ['Workshops', 'workshops'],
        'sponsors'      => ['Sponsors', 'sponsors'],
        'booths'        => ['Booths', 'sponsors'],
        'checkin'       => ['Registration', 'checkin'],
        'certificates'  => ['Certificates', 'certificates'],
        'settings'      => ['Settings', 'owner'],
        'users'         => ['Users', 'owner'],
        'communications' => ['Communication center', 'communications'],
        'backups'       => ['Backups', 'owner'],
    ];

    public static array $user = [];

    /** Small inline icons keep the navigation independent of external fonts. */
    public static function navIcon(string $name): string
    {
        $drawing = match ($name) {
            'index' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'registrations' => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="9" r="2"/><path d="M8 16c0-3 8-3 8 0M9 3v-1m6 1v-1"/>',
            'lists' => '<path d="M9 5h12M9 12h12M9 19h12M3 5h1M3 12h1M3 19h1"/>',
            'payments' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/>',
            'workshops' => '<path d="M12 5v16M3 4c4-1 7 0 9 2 2-2 5-3 9-2v15c-4-1-7 0-9 2-2-2-5-3-9-2Z"/>',
            'sponsors' => '<path d="M4 21V8l8-5 8 5v13M2 21h20M9 21v-5h6v5M8 9h1m6 0h1m-8 4h1m6 0h1"/>',
            'booths' => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 8l3-5h12l3 5M8 8v13m8-13v13M3 13h18"/>',
            'checkin' => '<path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M8 12l3 3 5-6"/>',
            'certificates' => '<circle cx="12" cy="9" r="6"/><path d="m8 14-2 7 6-3 6 3-2-7m-7-6 2 2 3-4"/>',
            'settings' => '<path d="m9 3-.7 2.2-2 .9L4 5.6 2 9l1.7 1.6v2.8L2 15l2 3.4 2.3-.5 2 .9L9 21h6l.7-2.2 2-.9 2.3.5 2-3.4-1.7-1.6v-2.8L22 9l-2-3.4-2.3.5-2-.9L15 3Z"/><circle cx="12" cy="12" r="3"/>',
            'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
            'content' => '<path d="m15 4 5 5M4 20l4-1L21 6a2 2 0 0 0-5-3L3 16l-1 5Z"/>',
            'logout' => '<path d="M9 3H4v18h5M10 12h11m-4-4 4 4-4 4"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
            'communications' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
            'backups' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
            'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4m8-4v4M7 14h2m4 0h2m-8 3h2"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'ticket' => '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4Z"/><path d="M15 5v3m0 3v2m0 3v3"/>',
            'lunch' => '<path d="M4 3v7m3-7v7m3-7v7M4 8h6m-3 2v11M20 3c-4 2-5 8 0 8v10V3Z"/>',
            'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
            'camera' => '<path d="M3 7h4l2-3h6l2 3h4v14H3Z"/><circle cx="12" cy="13" r="4"/>',
            'external' => '<path d="M14 3h7v7m0-7L10 14M10 3H3v18h18v-7"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/>',
            'phone' => '<path d="m7 3 3 5-3 2a14 14 0 0 0 7 7l2-3 5 3-1 3c-1 3-7 0-11-4S2 6 4 4Z"/>',
            'phone-off' => '<path d="m3 3 18 18M7 3l3 5-1 1m5 8 2-3 5 3-1 3c-1 3-7 0-11-4S2 6 4 4"/>',
            'thumb' => '<path d="M8 21H3V10h5m0 11h10a2 2 0 0 0 2-2l1-7a2 2 0 0 0-2-2h-6l1-5a2 2 0 0 0-4-1l-2 6Z"/>',
            'cash' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 9h.01M18 15h.01"/>',
            'bank' => '<path d="m3 8 9-5 9 5H3Zm2 3v7m7-7v7m7-7v7M3 21h18"/>',
            'security' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
            default => '',
        };
        return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $drawing . '</svg>';
    }

    /** Use the same original logo and crop as the public website. */
    private static function logo(): string
    {
        return '<span class="brand-mark"><svg class="ismile-logo" viewBox="0 0 1080 1155" role="img" aria-label="iSmile logo"><svg width="1080" height="1155" viewBox="0 0 1080 1155" overflow="hidden"><image href="../assets/brand/ismile-logo.png" width="2481" height="1155"/></svg></svg></span>';
    }

    /** Consistent summaries across the operations pages; values come from their queries. */
    public static function stats(array $items): string
    {
        $html = '<div class="operation-summary no-print">';
        foreach ($items as [$label, $value, $note, $icon, $colour]) {
            $html .= '<div class="metric-card ' . self::e($colour) . '"><div class="metric-top"><span>' . self::e($label) . '</span><i class="metric-icon">' . self::navIcon($icon) . '</i></div><strong>' . self::e($value) . '</strong><small>' . self::e($note) . '</small></div>';
        }
        return $html . '</div>';
    }

    public static function panelHeading(string $title, string $description, string $icon): string
    {
        return '<div class="panel-heading"><span class="panel-icon">' . self::navIcon($icon) . '</span><div><h2>' . self::e($title) . '</h2><p>' . self::e($description) . '</p></div></div>';
    }

    public static function emptyState(string $title, string $description, string $icon): string
    {
        return '<div class="empty-state"><span class="empty-icon">' . self::navIcon($icon) . '</span><strong>' . self::e($title) . '</strong><p>' . self::e($description) . '</p></div>';
    }

    /**
     * Call first on every admin page. Sends the security headers, makes sure
     * someone is signed in (with the phone code where the role needs it) and
     * that their role may open this area. Returns the user.
     */
    public static function guard(string $area): array
    {
        self::headers();
        $user = Auth::user();
        if ($user === null) {
            self::redirect('login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
        }
        if (Auth::needsTwoFactor($user) && !(int) $user['totp_enabled'] && basename((string) $_SERVER['SCRIPT_NAME']) !== 'two-factor.php') {
            self::redirect('two-factor.php');
        }
        // 'self' = any signed-in person (their own phone-code setup, for example).
        $allowed = match ($area) {
            'self'  => true,
            'owner' => $user['role'] === 'owner',
            default => Auth::can($user, $area),
        };
        if (!$allowed) {
            http_response_code(403);
            self::$user = $user;
            self::top('Not allowed', '');
            echo '<div class="card"><h2>Not allowed</h2><p>Your role (' . self::e($user['role']) . ') cannot open this page.</p><p><a class="btn" href="' . self::home($user) . '">Go to your start page</a></p></div>';
            self::bottom();
            exit;
        }
        self::$user = $user;
        return $user;
    }

    public static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
    }

    /** The overview stays in this Database; its editor may be hosted separately. */
    public static function contentUrl(): string
    {
        return 'content.php';
    }

    public static function contentEditorUrl(): string
    {
        return (string) App::config('content_editor_url', '') ?: '../admin.html';
    }

    /** The first page a role sees after signing in. */
    public static function home(array $user): string
    {
        return match ($user['role']) {
            'checkin' => 'checkin.php',
            'content' => self::contentUrl(),
            default   => 'index.php',
        };
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    /** Runs a POST action with the form token checked; errors become a red message. */
    public static function action(callable $work, string $back): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        try {
            Auth::checkCsrf();
            $message = $work();
            if (is_string($message) && $message !== '') {
                self::flash('ok', $message);
            }
        } catch (UserError $error) {
            self::flash('error', $error->key);
        } catch (\Throwable $error) {
            App::log('error', 'Admin action failed', ['page' => $_SERVER['SCRIPT_NAME'] ?? '', 'error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()]);
            self::flash('error', 'Something went wrong. Nothing was changed. (The error is in the server log.)');
        }
        self::redirect($back);
    }

    public static function flash(string $kind, string $message): void
    {
        Auth::startSession();
        $_SESSION['flash'][] = [$kind, $message];
    }

    public static function top(string $title, string $current, string $actions = ''): void
    {
        $user = self::$user;
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex"><title>' . self::e($title) . ' · iSmile Database</title><link rel="icon" href="../assets/favicon-32.png?v=1" type="image/png" sizes="32x32">';
        // The version follows the files' date, so a change is never hidden by the browser's cache.
        $v = static fn (string $file): int => (int) @filemtime(App::siteFile('admin/' . $file)) ?: 1;
        echo '<link rel="stylesheet" href="admin.css?v=' . $v('admin.css') . '"><script src="admin.js?v=' . $v('admin.js') . '" defer></script></head><body class="' . ($user ? 'admin-shell' : 'database-auth') . '" data-page="' . self::e($current) . '">';
        if ($current === 'checkin') echo '<script src="vendor/jsQR.js" defer></script>';
        if ($user) {
            echo '<a class="skip-link" href="#main-content">Skip to content</a>';
            echo '<aside class="sidebar" id="admin-sidebar" aria-label="Database navigation">';
            echo '<div class="sidebar-brand"><a class="brand" href="' . self::home($user) . '">' . self::logo() . '<span>i<b>Smile</b><small>Database</small></span></a><button class="sidebar-close icon-button" type="button" aria-label="Close navigation" hidden>' . self::navIcon('close') . '</button></div>';
            echo '<nav class="sidebar-nav" aria-label="Database pages">';
            $groups = [
                'Workspace' => ['index'],
                'Event operations' => ['registrations', 'lists', 'payments', 'workshops', 'sponsors', 'booths', 'checkin', 'certificates'],
                'Database' => ['communications', 'settings', 'users', 'backups'],
            ];
            foreach ($groups as $group => $files) {
                $links = '';
                foreach ($files as $file) {
                    [$label, $area] = self::MENU[$file];
                    $can = $area === 'owner' ? $user['role'] === 'owner' : Auth::can($user, $area);
                    if ($can) {
                        $links .= '<a class="nav-link' . ($file === $current ? ' on' : '') . '"' . ($file === $current ? ' aria-current="page"' : '') . ' href="' . $file . '.php">' . self::navIcon($file) . '<span>' . self::e($label) . '</span></a>';
                    }
                }
                if ($group === 'Database' && Auth::can($user, 'content')) {
                    $links .= '<a class="nav-link' . ($current === 'content' ? ' on' : '') . '"' . ($current === 'content' ? ' aria-current="page"' : '') . ' href="' . self::e(self::contentUrl()) . '"' . (str_starts_with(self::contentUrl(), 'http') ? ' target="_blank" rel="noopener"' : '') . '>' . self::navIcon('content') . '<span>Site content</span>' . (str_starts_with(self::contentUrl(), 'http') ? '<span class="nav-external">' . self::navIcon('external') . '</span>' : '') . '</a>';
                }
                if ($links !== '') {
                    echo '<div class="nav-group"><p class="nav-caption">' . self::e($group) . '</p>' . $links . '</div>';
                }
            }
            echo '</nav><div class="sidebar-footer"><div class="sidebar-account"><span class="account-avatar" aria-hidden="true">' . self::e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) . '</span><div><b>' . self::e($user['name']) . '</b><span>' . self::e(ucfirst($user['role'])) . '</span></div></div>';
            echo '<form method="post" action="logout.php">' . self::csrfField() . '<button class="sidebar-signout" type="submit">' . self::navIcon('logout') . '<span>Sign out</span></button></form><p class="sidebar-footnote">iSmile 2026 &middot; Team workspace</p></div></aside>';
            echo '<button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1" hidden></button><div class="workspace" id="admin-workspace"><header class="workspace-header"><div class="workspace-heading"><button class="sidebar-toggle icon-button" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false" hidden>' . self::navIcon('menu') . '</button><div><span class="workspace-eyebrow">iSmile 2026</span><span class="workspace-breadcrumb">Database <span aria-hidden="true">/</span> <b>' . self::e($title) . '</b></span></div></div>';
            if (Auth::can($user, 'registrations')) {
                echo '<form data-guest-suggestions class="workspace-search" action="registrations.php" method="get" role="search">' . self::navIcon('search') . '<input type="search" name="q" aria-label="Search registrations by name, phone or reference" placeholder="Search name, phone or reference…"><button type="submit" aria-label="Search registrations">' . self::navIcon('search') . '</button></form>';
            }
            echo '<span class="workspace-status"><i aria-hidden="true"></i>' . (App::isLive() ? 'Live environment' : 'Test environment') . '</span></header>';
        } else {
            echo '<header class="bar"><a class="brand" href="index.php">' . self::logo() . '<span>i<b>Smile</b><small>Database</small></span></a></header>';
        }
        $description = match ($current) {
            'index' => 'Your event at a glance. People, payments and the next task, all in one place.',
            'registrations' => 'Look after every guest, from their first call to their event ticket.',
            'lists' => 'Find the right people and prepare clear lists for your team.',
            'payments' => 'Review payments, follow up on issues and keep every amount accounted for.',
            'workshops' => 'Organise workshops, manage bookings and keep track of available seats.',
            'sponsors' => 'Build your partnerships and keep every conversation moving forward.',
            'booths' => 'Manage Standard booth bookings, agreed amounts, payments and follow-up calls.',
            'checkin' => 'Welcome every guest. Follow attendance, lunch bookings and live event reports.',
            'settings' => 'Make the database work for your team. Manage registration, emails and ambassador codes.',
            'users' => 'Give your team the right access to support a smooth event.',
            'content' => 'Bring your event to life. Manage the words, photos and details your guests see.',
            'communications' => 'Keep track of every event email. See what was sent, what is waiting and what needs attention.',
            'backups' => 'Keep a copy of your event records. Check your latest successful backup and download it securely.',
            default => $user ? '' : 'Welcome to your iSmile workspace. Sign in to continue.',
        };
        $section = $current === 'index' ? 'Overview' : (in_array($current, ['registrations', 'lists', 'payments', 'workshops', 'sponsors', 'booths', 'checkin'], true) ? 'Event operations' : 'Database');
        echo '<main class="page" id="main-content" tabindex="-1"><div class="page-heading"><div class="page-heading-copy">' . ($user ? '<div class="page-title-row"><span class="page-symbol">' . self::navIcon($current !== '' ? $current : 'security') . '</span><div><span class="page-kicker">' . $section . '</span><h1>' . self::e($title) . '</h1></div></div>' : '<h1>' . self::e($title) . '</h1>') . ($description !== '' ? '<p>' . self::e($description) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="page-actions no-print">' . $actions . '</div>' : '') . '</div>';
        Auth::startSession();
        foreach ($_SESSION['flash'] ?? [] as [$kind, $message]) {
            echo '<div class="flash ' . ($kind === 'ok' ? 'ok' : 'err') . '" role="' . ($kind === 'ok' ? 'status' : 'alert') . '">' . self::e($message) . '</div>';
        }
        unset($_SESSION['flash']);
    }

    /** Read-only regions update in place while the user keeps their filters. */
    public static function liveUpdates(): string
    {
        return '<div class="live-report-toolbar no-print" data-live-directory><div><span class="live-report-badge"><i></i> Live updates</span><span class="muted small">New records appear automatically</span></div><div><span data-live-directory-status role="status" aria-live="polite">Checking for updates every 5 seconds</span><button type="button" class="btn small ghost" data-live-directory-refresh>Update now</button></div></div>';
    }

    public static function bottom(): void
    {
        echo '</main>' . (self::$user ? '</div>' : '') . '</body></html>';
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(Auth::csrf()) . '">';
    }

    public static function e(mixed $text): string
    {
        return Security::e($text);
    }

    public static function money(?int $amount, string $currency = 'IQD'): string
    {
        return $amount === null ? '–' : number_format($amount) . ' ' . $currency;
    }

    public static function when(?string $datetime): string
    {
        return $datetime ? date('j M, H:i', strtotime($datetime)) : '–';
    }

    /** A coloured label for a status, so what needs attention stands out. */
    public static function pill(string $status): string
    {
        $colour = match ($status) {
            'paid', 'approved', 'sent', 'confirmed', 'complimentary', 'arrived' => 'green',
            'unpaid', 'waiting', 'pending', 'created', 'contacted', 'agreed', 'new' => 'gold',
            'failed', 'rejected', 'mismatch', 'duplicate', 'cancelled', 'declined', 'expired' => 'red',
            'kept', 'waiting_list' => 'violet',
            default => 'grey',
        };
        return '<span class="pill ' . $colour . '">' . self::e(str_replace('_', ' ', $status)) . '</span>';
    }

    /**
     * The big "has this person paid?" badge shown next to names. Works for a
     * registration status (paid / complimentary / unpaid / waiting / cancelled)
     * and for a workshop payment status (paid / complimentary / unpaid).
     */
    public static function paidBadge(string $status): string
    {
        [$class, $label, $icon] = match ($status) {
            'paid'          => ['paid-yes', 'PAID', 'check'],
            'complimentary' => ['paid-free', 'FREE', 'star'],
            'cancelled'     => ['paid-cancel', 'CANCELLED', 'close'],
            default         => ['paid-no', 'NOT PAID', 'close'],
        };
        return '<span class="paid-badge ' . $class . '">' . self::navIcon($icon) . '<span>' . $label . '</span></span>';
    }

    /**
     * "Book a workshop" for one person: which workshop, the price, and whether
     * the money was received now (exact amount) or is still to collect.
     */
    public static function workshopAddForm(array $user, array $registration, string $action = ''): string
    {
        $isOwner = $user['role'] === 'owner';
        $options = '';
        foreach (SiteData::workshops() as $workshop) {
            $left = (int) ($workshop['totalSeats'] ?? 0) - Office::bookedCount($workshop['id']);
            $price = (int) ($workshop['price'] ?? 0);
            $options .= '<option value="' . self::e($workshop['id']) . '" data-price="' . $price . '">' . self::e(SiteData::workshopName($workshop))
                . ' — ' . ($price > 0 ? number_format($price) . ' IQD' : 'no price yet') . ' — ' . max(0, $left) . ' seats left</option>';
        }
        return '<form method="post" class="book-form"' . ($action !== '' ? ' action="' . self::e($action) . '"' : '') . '>'
            . self::csrfField()
            . '<input type="hidden" name="do" value="workshop_add"><input type="hidden" name="rid" value="' . (int) $registration['id'] . '">'
            . '<label>Workshop<select name="workshop" required data-price-source><option value="">Choose…</option>' . $options . '</select></label>'
            . ($isOwner ? '<label>Price (IQD, Owner may change)<input name="price" type="number" min="0" step="500" placeholder="workshop price" data-price-target></label>' : '')
            . '<fieldset class="pay-choice"><legend>Paid?</legend>'
            . '<label><input type="radio" name="payment_status" value="unpaid" checked> ✗ Not paid yet</label>'
            . '<label><input type="radio" name="payment_status" value="paid"> ✓ Paid now</label>'
            . ($isOwner ? '<label><input type="radio" name="payment_status" value="complimentary"> ★ Free (Owner)</label>' : '')
            . '</fieldset>'
            . '<div class="paid-fields"><label>Amount received (IQD)<input name="amount_paid" inputmode="numeric" placeholder="exactly the price"></label>'
            . '<label>How<select name="paid_how"><option value="">choose…</option><option value="cash">cash</option><option value="transfer">transfer</option><option value="psoola">Psoola</option></select></label></div>'
            . ($isOwner ? '<label class="inline"><input type="checkbox" name="override" value="1"> over capacity</label>' : '')
            . '<div class="form-actions"><button class="btn green">Book the workshop</button></div></form>';
    }

    /** Mark one workshop booking paid / not paid, or remove it. */
    public static function workshopPaymentForm(array $booking, array $user, string $action = ''): string
    {
        $isOwner = $user['role'] === 'owner';
        $paid = $booking['payment_status'] === 'paid';
        $open = '<form method="post" class="inline-form"' . ($action !== '' ? ' action="' . self::e($action) . '"' : '') . '>' . self::csrfField()
            . '<input type="hidden" name="do" value="workshop_change"><input type="hidden" name="booking" value="' . (int) $booking['id'] . '"><input type="hidden" name="rid" value="' . (int) $booking['registration_id'] . '">';
        $html = '<div class="row-actions booking-actions">';
        if ($booking['payment_status'] !== 'paid') {
            $html .= $open . '<input type="hidden" name="payment_status" value="paid">'
                . '<input name="amount_paid" aria-label="Workshop amount received" inputmode="numeric" size="9" placeholder="' . number_format((int) $booking['price_agreed']) . '" required>'
                . '<select name="paid_how" aria-label="Workshop payment method" required><option value="">how?</option><option value="cash">cash</option><option value="transfer">transfer</option><option value="psoola">Psoola</option></select>'
                . '<div class="form-actions compact"><button class="btn small green">' . self::navIcon('check') . '<span>Mark paid</span></button></div></form>';
        }
        if ($paid && $isOwner) {
            $html .= '<div class="row-secondary-actions">' . $open . '<input type="hidden" name="payment_status" value="unpaid"><button class="btn small ghost" data-confirm="Undo this payment? Only for a mistake: workshop money is not refunded.">Undo (mistake)</button></form></div>';
        }
        if (!$paid || $isOwner) {
            $html .= '<div class="row-danger-actions">' . $open . '<input type="hidden" name="remove" value="1"><button class="btn small red" data-confirm="Remove this workshop booking? The seat becomes free on the website.' . ($paid ? ' The money is NOT refunded.' : '') . '">Remove</button></form></div>';
        }
        return $html . '</div>';
    }

    public static function post(string $key, int $max = 500): string
    {
        return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    }

    public static function query(string $key, int $max = 120): string
    {
        return mb_substr(trim((string) ($_GET[$key] ?? '')), 0, $max);
    }
}
