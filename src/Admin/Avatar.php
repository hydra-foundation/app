<?php

declare(strict_types=1);

namespace App\Admin;

use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\MaxFileSize;
use Hydra\Validation\Rules\MimeType;
use Hydra\Validation\Rules\UploadedFile;

/**
 * What an avatar is allowed to be, written down once. The Users module and the
 * account settings screen both take one, and a picture one of them refuses
 * must not be a picture the other accepts.
 */
final class Avatar
{
    public const DIRECTORY = 'avatars';
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const HELP = 'JPEG, PNG, WebP or GIF, up to 2 MB.';
    public const ICON = 'person-circle';

    /** The column, as the list and the user screen show it. */
    public static function field(): Field
    {
        return Field::image('avatar')->labelled('Avatar')->fallbackIcon(self::ICON)->nameFrom('avatar_name');
    }

    /** The control on the Users module's forms. */
    public static function input(): Input
    {
        return Input::file('avatar')
            ->labelled('Avatar')
            ->storedIn(self::DIRECTORY)
            ->accepts(...self::TYPES)
            ->maxSize(self::MAX_BYTES)
            ->removable()
            ->keepsName()
            ->help(self::HELP);
    }

    /**
     * The same checks, for a screen that takes an avatar without a module form.
     *
     * @return list<RuleInterface>
     */
    public static function rules(): array
    {
        return [new UploadedFile, new MimeType(...self::TYPES), new MaxFileSize(self::MAX_BYTES)];
    }
}
