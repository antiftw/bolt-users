<?php

declare(strict_types=1);

namespace Bolt\UsersExtension\Enum;

use Tightenco\Collect\Support\Collection;

class UserStatus
{
    public const string ENABLED = 'enabled';
    public const string DISABLED = 'disabled';
    public const string EMAIL_CONFIRMATION = 'email_confirmation';
    public const string ADMIN_CONFIRMATION = 'admin_confirmation';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            static::ENABLED,
            static::DISABLED,
            static::EMAIL_CONFIRMATION,
            static::ADMIN_CONFIRMATION,
        ];
    }

    public static function isValid(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        return (new Collection(static::all()))->containsStrict($status);
    }
}
