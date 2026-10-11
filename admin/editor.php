<?php
// "Site content": opens the website editor already signed in as this person,
// with a pass that works once for one minute (EditorHandoff).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\EditorHandoff;

$user = Page::guard('content');
$group = preg_match('/^[A-Za-z]{1,40}$/', Page::query('group')) ? Page::query('group') : '';
$editor = Page::contentEditorUrl();
Page::redirect($editor . (str_contains($editor, '?') ? '&' : '?') . 'handoff=' . rawurlencode(EditorHandoff::issue($user)) . ($group !== '' ? '#' . $group : ''));
