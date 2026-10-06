<?php
// A problem the visitor can fix. The message is a translation key the website
// already knows (for example err_phone), so it is shown in their language.

declare(strict_types=1);

namespace Ismile;

final class UserError extends \RuntimeException
{
    public function __construct(public readonly string $key, public readonly ?string $field = null, public readonly int $status = 422)
    {
        parent::__construct($key);
    }
}
