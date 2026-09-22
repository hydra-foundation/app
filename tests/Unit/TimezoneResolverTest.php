<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entities\Role;
use App\Entities\User;
use App\Repositories\PreferenceRepository;
use App\Tests\Support\TestSchema;
use App\View\TimezoneResolver;
use App\View\Timezones;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which clock a page reads by. Everything is stored in UTC, so this is the one
 * place that decides what a person actually sees, and the failures it can have
 * are quiet ones: a zone nobody chose, or an identifier that no longer exists
 * and throws somewhere else entirely.
 */
#[CoversClass(TimezoneResolver::class)]
#[CoversClass(Timezones::class)]
final class TimezoneResolverTest extends TestCase
{
    private PDO $pdo;
    private PreferenceRepository $preferences;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, password_hash) VALUES ('will', 'x')");

        $this->preferences = new PreferenceRepository(new PdoConnection($this->pdo));
    }

    public function test_a_visitor_nobody_knows_gets_the_application_default(): void
    {
        $resolver = new TimezoneResolver(new FakeGuard(null), $this->preferences, new Timezones('America/Regina'));

        // The login screen and the public pages have nobody to ask, and a
        // resolver that read the session anyway would need one to exist.
        $this->assertSame('America/Regina', $resolver->current());
    }

    public function test_a_user_who_has_chosen_nothing_gets_the_application_default(): void
    {
        $this->assertSame('UTC', $this->resolverFor($this->user())->current());
    }

    public function test_a_saved_zone_wins(): void
    {
        $this->preferences->set(1, Timezones::PREFERENCE, 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $this->resolverFor($this->user())->current());
    }

    public function test_a_zone_that_no_longer_exists_falls_back_rather_than_throwing(): void
    {
        // Zones are retired. A row written years ago must not take the whole
        // admin down the first time somebody opens a list with a date in it.
        $this->preferences->set(1, Timezones::PREFERENCE, 'Mars/Olympus_Mons');

        $resolver = $this->resolverFor($this->user());

        $this->assertSame('UTC', $resolver->current());
        $this->assertSame('UTC', $resolver->zone()->getName());
    }

    public function test_the_zone_is_the_one_the_name_says(): void
    {
        $this->preferences->set(1, Timezones::PREFERENCE, 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $this->resolverFor($this->user())->zone()->getName());
    }

    public function test_the_picker_offers_utc_on_its_own(): void
    {
        $options = (new Timezones)->options();

        $this->assertSame(['UTC' => 'UTC'], $options['UTC']);
    }

    public function test_the_picker_groups_by_region_and_reads_as_place_names(): void
    {
        $options = (new Timezones)->options();

        $this->assertArrayHasKey('America', $options);
        $this->assertSame('New York', $options['America']['America/New_York']);
    }

    public function test_an_offset_is_not_a_place_and_is_not_offered(): void
    {
        // DateTimeZone accepts "+05:00" and "EST" happily, and neither says
        // where anybody is or follows them through a daylight saving change.
        $timezones = new Timezones;

        $this->assertFalse($timezones->has('+05:00'));
        $this->assertFalse($timezones->has('EST'));
        $this->assertTrue($timezones->has('America/Regina'));
    }

    private function resolverFor(User $user): TimezoneResolver
    {
        return new TimezoneResolver(new FakeGuard($user), $this->preferences, new Timezones);
    }

    private function user(): User
    {
        return new User(1, 'will', 'x', Role::User, '2026-01-01 00:00:00');
    }
}
