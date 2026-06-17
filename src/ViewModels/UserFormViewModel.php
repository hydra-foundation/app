<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * View model for the admin user form, shared by the create and edit pages.
 *
 * Carries the field values to (re)fill and any per-field validation errors. A
 * null {@see $id} means the create form (POST /admin/users); a set id means the
 * edit form for that user (POST /admin/users/{id}). Unlike the login form, which
 * shows a single deliberately-vague summary, this admin form shows one message
 * per field — there is nothing to conceal from an admin fixing their own input.
 *
 * The password is never carried back: a re-render always starts that field empty.
 */
final readonly class UserFormViewModel
{
    /** @param array<string, string> $errors keyed by field name */
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

    /** Where the form submits — create collects at the collection, edit at the member. */
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
