<?php

declare(strict_types=1);

namespace App\ViewModels;

/**
 * The error half of a form view model
 */
trait FormErrors
{
    abstract private function fields(): array;

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasError(string $name): bool
    {
        return key_exists($name, $this->errors);
    }

    public function error(string $name): string
    {
        return $this->errors[$name] ?? '';
    }

    public function hasFormErrors(): bool
    {
        return $this->formErrors() !== [];
    }

    public function formErrors(): array
    {
        return array_values(array_diff_key($this->errors, array_flip($this->fields())));
    }
}
