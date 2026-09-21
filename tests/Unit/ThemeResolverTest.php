<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entities\Role;
use App\Entities\User;
use App\Repositories\PreferenceRepository;
use App\Tests\Support\SignedInAs;
use App\Tests\Support\TestSchema;
use App\View\ThemeResolver;
use App\View\Themes;
use App\View\TimezoneResolver;
use App\View\Timezones;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeResolver::class)]
#[CoversClass(Themes::class)]
final class ThemeResolverTest extends TestCase
{
    private const THEMES = __DIR__ . '/../../public/css/themes';

    private PreferenceRepository $preferences;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $pdo = TestSchema::connect();
        $pdo->exec("INSERT INTO users (username, password_hash) VALUES ('will', 'x')");

        $this->preferences = new PreferenceRepository(new PdoConnection($pdo));
        $this->clock = new FrozenClock;
    }

    public function test_a_saved_palette_is_both_the_choice_and_the_paint(): void
    {
        $this->preferences->set(1, Themes::PREFERENCE, 'nord');
        $resolver = $this->resolver();

        $this->assertSame('nord', $resolver->choice());
        $this->assertSame('nord', $resolver->current());
    }

    /** @return iterable<string, array{string, string}> */
    public static function hours(): iterable
    {
        yield 'before the day' => ['07:59', Themes::NIGHT];
        yield 'the day starting' => ['08:00', Themes::DAY];
        yield 'the day ending' => ['17:59', Themes::DAY];
        yield 'after the day' => ['18:00', Themes::NIGHT];
        yield 'midnight' => ['00:00', Themes::NIGHT];
    }

    #[DataProvider('hours')]
    public function test_auto_follows_the_working_day(string $time, string $palette): void
    {
        $this->preferences->set(1, Themes::PREFERENCE, Themes::AUTO);
        $this->clock->set("2026-09-21T{$time}:00+00:00");
        $resolver = $this->resolver();

        $this->assertSame(Themes::AUTO, $resolver->choice());
        $this->assertSame($palette, $resolver->current());
    }

    public function test_auto_reads_the_hour_off_the_readers_clock_not_the_servers(): void
    {
        $this->preferences->set(1, Themes::PREFERENCE, Themes::AUTO);
        $this->preferences->set(1, Timezones::PREFERENCE, 'America/Regina');

        // 20:00 in UTC is 14:00 in Regina: evening on the server, mid-afternoon
        // for the person reading.
        $this->clock->set('2026-09-21T20:00:00+00:00');

        $this->assertSame(Themes::DAY, $this->resolver()->current());
    }

    public function test_auto_is_offered_first_and_never_linked(): void
    {
        $themes = new Themes(self::THEMES);

        $this->assertSame(Themes::AUTO, array_key_first($themes->options()));
        $this->assertTrue($themes->has(Themes::AUTO));
        $this->assertNotContains(Themes::AUTO, $themes->names());
    }

    public function test_a_signed_out_page_takes_the_fallback_whatever_the_hour(): void
    {
        $this->clock->set('2026-09-21T23:00:00+00:00');
        $resolver = $this->resolver(signedIn: false);

        $this->assertSame(Themes::FALLBACK, $resolver->current());
    }

    private function resolver(bool $signedIn = true): ThemeResolver
    {
        $guard = new SignedInAs($signedIn ? new User(1, 'will', 'x', Role::User, '2026-01-01 00:00:00') : null);

        return new ThemeResolver(
            $guard,
            $this->preferences,
            new Themes(self::THEMES),
            $this->clock,
            new TimezoneResolver($guard, $this->preferences, new Timezones),
        );
    }
}
