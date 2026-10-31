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
$mapTexts = 0;
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
        // Only the map's booth count changed; preserve all other edited text.
        $changed = false;
        foreach (['bm_intro', 'pg_map_text', 'pm_text'] as $key) {
            if (!is_string($server[$key] ?? null)) continue;
            $updated = str_replace(['44', '٤٤'], ['39', '٣٩'], $server[$key]);
            if ($updated !== $server[$key]) {
                $server[$key] = $updated;
                $changed = true;
                $mapTexts++;
            }
        }
        if (is_string($server['bm_size_note'] ?? null)) {
            $updated = preg_replace(['/(?<![0-9٠-٩])24(?![0-9٠-٩])/u', '/(?<![0-9٠-٩])6(?![0-9٠-٩])/u', '/٢٤/u', '/٦/u'], ['21', '5', '٢١', '٥'], $server['bm_size_note']);
            if ($updated !== $server['bm_size_note']) {
                $server['bm_size_note'] = $updated;
                $changed = true;
                $mapTexts++;
            }
        }
        if ($missing || $changed) {
            file_put_contents($target, json_encode($server + $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            $keys += count($missing);
        }
    }
}
echo "data: $copied new file(s), $keys new translation key(s), $mapTexts map count(s) updated; other edited content preserved.\n";
