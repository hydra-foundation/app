<?php

declare(strict_types=1);

namespace App\ViewModels;

use App\Admin\Avatar;

/** The avatar row on Settings › Account: the picture, if any, and what went wrong. */
final readonly class AvatarViewModel
{
    use FormErrors;

    /** @param array<string, string> $errors */
    public function __construct(
        public ?string $url = null,
        public array $errors = [],
    ) {}

    public function hasAvatar(): bool
    {
        return $this->url !== null;
    }

    /** The accept= list, the same types the server holds an upload to. */
    public function accept(): string
    {
        return implode(',', Avatar::TYPES);
    }

    public function help(): string
    {
        return Avatar::HELP;
    }

    public function icon(): string
    {
        return Avatar::ICON;
    }

    /** @return list<string> */
    private function fields(): array
    {
        return ['avatar'];
    }
}
