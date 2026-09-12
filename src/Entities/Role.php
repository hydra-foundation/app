<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * The set of roles a user may hold. Adding a case is the only edit needed:
 * the admin select, the console option and the dashboard tiles all read
 * their choices from here.
 */
enum Role: string
{
    case User = 'user';
    case Admin = 'admin';

    /**
     * The role a user holds when none was given, matching the column default
     */
    public const DEFAULT = self::User;

    /**
     * Read a role off untrusted input (a form field, a console option, a column
     * written before a case was renamed), falling back to the default.
     */
    public static function coerce(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::DEFAULT : self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Admin => 'Admin',
        };
    }

    /**
     * Value-keyed labels, the shape select fields and inputs expect
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * Every role's stored value, for a validation message or a choice list
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
