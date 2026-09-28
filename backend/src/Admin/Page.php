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
        'sponsors'      => ['Sponsors & booths', 'sponsors'],
        'checkin'       => ['Check-in', 'checkin'],
        'settings'      => ['Settings', 'owner'],
        'users'         => ['Users', 'owner'],
    ];

    public static array $user = [];

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

    /**
     * The website editor (texts, pictures, prices). Normally admin.html next to
     * this admin; when the website runs on another server (config.php
     * content_editor_url, e.g. https://ismile.krd/admin.html), that one.
     */
    public static function contentUrl(): string
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

    public static function top(string $title, string $current): void
    {
        $user = self::$user;
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex"><title>' . self::e($title) . ' · iSmile admin</title>';
        // The version follows the files' date, so a change is never hidden by the browser's cache.
        $v = static fn (string $file): int => (int) @filemtime(App::siteFile('admin/' . $file)) ?: 1;
        echo '<link rel="stylesheet" href="admin.css?v=' . $v('admin.css') . '"><script src="admin.js?v=' . $v('admin.js') . '" defer></script></head><body>';
        echo '<header class="bar"><a class="brand" href="' . ($user ? self::home($user) : 'index.php') . '">i<b>Smile</b> admin</a>';
        if ($user) {
            echo '<nav class="menu">';
            foreach (self::MENU as $file => [$label, $area]) {
                $can = $area === 'owner' ? $user['role'] === 'owner' : Auth::can($user, $area);
                if ($can) {
                    echo '<a class="' . ($file === $current ? 'on' : '') . '" href="' . $file . '.php">' . self::e($label) . '</a>';
                }
            }
            if (Auth::can($user, 'content')) {
                echo '<a href="' . self::e(self::contentUrl()) . '"' . (str_starts_with(self::contentUrl(), 'http') ? ' target="_blank" rel="noopener"' : '') . '>Site content</a>';
            }
            echo '</nav><form method="post" action="logout.php" class="who"><span>' . self::e($user['name']) . ' · ' . self::e($user['role']) . '</span>'
                . self::csrfField() . '<button class="btn small ghost">Sign out</button></form>';
        }
        echo '</header><main class="page"><h1>' . self::e($title) . '</h1>';
        Auth::startSession();
        foreach ($_SESSION['flash'] ?? [] as [$kind, $message]) {
            echo '<div class="flash ' . ($kind === 'ok' ? 'ok' : 'err') . '">' . self::e($message) . '</div>';
        }
        unset($_SESSION['flash']);
    }

    public static function bottom(): void
    {
        echo '</main></body></html>';
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
        [$class, $label] = match ($status) {
            'paid'          => ['paid-yes', '✓ PAID'],
            'complimentary' => ['paid-free', '★ FREE'],
            'cancelled'     => ['paid-cancel', 'CANCELLED'],
            default         => ['paid-no', '✗ NOT PAID'],
        };
        return '<span class="paid-badge ' . $class . '">' . $label . '</span>';
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
            . '<button class="btn green">Book the workshop</button></form>';
    }

    /** Mark one workshop booking paid / not paid, or remove it. */
    public static function workshopPaymentForm(array $booking, array $user, string $action = ''): string
    {
        $isOwner = $user['role'] === 'owner';
        $paid = $booking['payment_status'] === 'paid';
        $open = '<form method="post" class="inline-form"' . ($action !== '' ? ' action="' . self::e($action) . '"' : '') . '>' . self::csrfField()
            . '<input type="hidden" name="do" value="workshop_change"><input type="hidden" name="booking" value="' . (int) $booking['id'] . '"><input type="hidden" name="rid" value="' . (int) $booking['registration_id'] . '">';
        $html = '';
        if ($booking['payment_status'] !== 'paid') {
            $html .= $open . '<input type="hidden" name="payment_status" value="paid">'
                . '<input name="amount_paid" inputmode="numeric" size="9" placeholder="' . number_format((int) $booking['price_agreed']) . '" required>'
                . '<select name="paid_how" required><option value="">how?</option><option value="cash">cash</option><option value="transfer">transfer</option><option value="psoola">Psoola</option></select>'
                . '<button class="btn small green">✓ Mark paid</button></form>';
        }
        if ($paid && $isOwner) {
            $html .= $open . '<input type="hidden" name="payment_status" value="unpaid"><button class="btn small ghost" data-confirm="Undo this payment? Only for a mistake: workshop money is not refunded.">Undo (mistake)</button></form>';
        }
        if (!$paid || $isOwner) {
            $html .= $open . '<input type="hidden" name="remove" value="1"><button class="btn small red" data-confirm="Remove this workshop booking? The seat becomes free on the website.' . ($paid ? ' The money is NOT refunded.' : '') . '">Remove</button></form>';
        }
        return $html;
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
