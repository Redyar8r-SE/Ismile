<?php
// Brings new content files and new translation keys from the project into the
// server's data/ folder WITHOUT overwriting anything the admin has changed.
//
//   php merge-data.php <project data folder> <server data folder>
//
// On the server, data/ belongs to the admin: prices, speakers, texts are
// edited there. An update must never replace those files. So:
//   - a data file that does not exist on the server yet is copied;
//   - a language file (data/i18n/*.json) gains only the keys it is missing;
//   - every other existing file is left exactly as it is.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc < 3) {
    fwrite(STDERR, "Usage: php merge-data.php <project data folder> <server data folder>\n");
    exit(1);
}
[$from, $to] = [rtrim($argv[1], '/\\'), rtrim($argv[2], '/\\')];
if (!is_dir($from)) {
    fwrite(STDERR, "No project data folder at $from\n");
    exit(1);
}

$copied = 0;
$keys = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
    $target = "$to/$relative";
    if (!is_file($target)) {
        @mkdir(dirname($target), 0755, true);
        copy($file->getPathname(), $target);
        $copied++;
        continue;
    }
    if (str_starts_with($relative, 'i18n/') && str_ends_with($relative, '.json')) {
        $project = json_decode((string) file_get_contents($file->getPathname()), true);
        $server = json_decode((string) file_get_contents($target), true);
        if (!is_array($project) || !is_array($server)) {
            fwrite(STDERR, "Skipped $relative: not valid JSON\n");
            continue;
        }
        $missing = array_diff_key($project, $server);
        if ($missing) {
            file_put_contents($target, json_encode($server + $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            $keys += count($missing);
        }
    }
}
echo "data: $copied new file(s), $keys new translation key(s); nothing overwritten.\n";
