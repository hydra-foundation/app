<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * User form view model
 *
 * View model for the admin user form, shared by the create and edit pages
 */
final readonly class UserFormViewModel
{
    public function __construct(
        public string $username = '',
        public string $role = 'user',
        public array $errors = [],
        public ?int $id = null,
    ) {}

    public function isEdit(): bool
    {
        return $this->id !== null;
    }

    public function action(): string
    {
        return $this->isEdit() ? "/admin/users/{$this->id}" : '/admin/users';
    }

    /** The message for one field, or null if it validated. */
    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
