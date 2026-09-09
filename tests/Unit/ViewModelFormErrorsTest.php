<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ViewModels\LoginViewModel;
use PHPUnit\Framework\TestCase;

/**
 * The field/form split the FormErrors trait provides.
 *
 * A validator error keyed to something the form draws no input for used to
 * render nowhere at all — a wrong-credentials login showed an empty form. These
 * pin the rule that closes that: a key the form does not own is form-level, and
 * the shared partials/form_errors partial draws it.
 */
final class ViewModelFormErrorsTest extends TestCase
{
    public function test_login_keeps_field_errors_off_the_form_level_list(): void
    {
        $vm = new LoginViewModel(errors: ['username' => 'Enter your username.']);

        $this->assertTrue($vm->hasError('username'));
        $this->assertFalse($vm->hasFormErrors());
        $this->assertSame([], $vm->formErrors());
    }

    public function test_login_surfaces_an_error_matching_no_field(): void
    {
        $vm = new LoginViewModel(errors: ['credentials' => 'No match.']);

        $this->assertFalse($vm->hasError('username'));
        $this->assertTrue($vm->hasFormErrors());
        $this->assertSame(['No match.'], $vm->formErrors());
    }

    public function test_login_surfaces_an_unanticipated_key(): void
    {
        // The case that bit us: a key nobody wrote a template branch for.
        $vm = new LoginViewModel(errors: ['throttled' => 'Too many attempts.']);

        $this->assertSame(['Too many attempts.'], $vm->formErrors());
    }
}
