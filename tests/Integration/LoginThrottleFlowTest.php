<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Two budgets on the password form: one per client, one per username. The
 * second is what a guessing run spread over many addresses cannot get round.
 */
#[CoversNothing]
final class LoginThrottleFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
    }

    public function test_one_client_is_refused_after_five_attempts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('boss', 'wrong', '203.0.113.1')->assertStatus(422);
        }

        $this->attempt('boss', 'wrong', '203.0.113.1')->assertStatus(429);
    }

    public function test_one_username_is_refused_after_ten_attempts_from_anywhere(): void
    {
        // Two guesses from each of five addresses: no client comes near its own budget.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('boss', 'wrong', '203.0.113.' . intdiv($i + 1, 2))->assertStatus(422);
        }

        $this->attempt('boss', TestApp::PASSWORD, '198.51.100.1')
            ->assertStatus(429)
            ->assertSee('Too many sign-in attempts for this account.');
    }

    public function test_the_budget_is_the_name_s_whatever_its_case(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt($i % 2 === 0 ? 'BOSS' : 'boss', 'wrong', '203.0.113.' . $i)->assertStatus(422);
        }

        $this->attempt('Boss', TestApp::PASSWORD, '198.51.100.1')->assertStatus(429);
    }

    public function test_an_unknown_name_is_refused_the_same_way(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('nobody', 'wrong', '203.0.113.' . $i)->assertStatus(422);
        }

        $this->attempt('nobody', 'wrong', '198.51.100.1')
            ->assertStatus(429)
            ->assertSee('Too many sign-in attempts for this account.');
    }

    public function test_another_username_keeps_its_own_budget(): void
    {
        $this->app->seed('clerk', Role::User);

        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('boss', 'wrong', '203.0.113.' . $i);
        }

        $this->attempt('clerk', TestApp::PASSWORD, '198.51.100.1')->assertRedirect('/admin');
    }

    private function attempt(string $username, string $password, string $peer): TestResponse
    {
        return $this->app->http()->from($peer)->post('/login', ['username' => $username, 'password' => $password]);
    }
}
