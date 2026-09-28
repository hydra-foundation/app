<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\Orphan;
use Hydra\Admin\Files\Reference;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * What the Files module will stand on, against the skeleton's own users: which
 * row a stored file belongs to, and which files belong to no row.
 */
#[CoversNothing]
final class FileReferencesFlowTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private TestApp $app;
    private int $clerk;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->clerk = $this->app->seed('clerk', Role::User);
        $this->app->login('boss')->assertStatus(302);
    }

    public function test_a_users_avatar_is_referenced_by_that_user(): void
    {
        $this->uploadAvatar('Clerk Portrait.png');
        $avatar = (string) $this->app->get(UserRepository::class)->byIdentifier($this->clerk)?->avatar;

        $this->assertEquals(
            [new Reference($avatar, 'users', (string) $this->clerk, 'avatar', 'Clerk Portrait.png')],
            $this->app->get(FileReferences::class)->to($avatar),
        );
    }

    public function test_a_file_no_row_points_at_is_not_an_orphan_on_the_day_it_was_stored(): void
    {
        $this->uploadAvatar('Clerk Portrait.png');
        $this->stray();

        $this->assertSame([], $this->orphans());
    }

    public function test_a_file_no_row_points_at_is_an_orphan_once_a_day_has_passed(): void
    {
        $this->uploadAvatar('Clerk Portrait.png');
        $stray = $this->stray();

        $this->app->container()->instance(ClockInterface::class, new FrozenClock(new DateTimeImmutable('+25 hours')));

        // The avatar is as old as the stray, and is not an orphan: a row points at it.
        $this->assertSame([$stray], $this->orphans());
    }

    /** A file put on the private disk by something that never wrote a row for it. */
    private function stray(): string
    {
        $disks = $this->app->get(Disks::class);

        return $disks->qualify(Disks::PRIVATE, $disks->private()->put('avatars', Stream::create(base64_decode(self::PNG))));
    }

    /** @return list<string> */
    private function orphans(): array
    {
        return array_map(
            static fn (Orphan $orphan): string => $orphan->qualified,
            iterator_to_array($this->app->get(FileReferences::class)->orphans(), false),
        );
    }

    private function uploadAvatar(string $name): void
    {
        $bytes = base64_decode(self::PNG);

        $this->app->http()
            ->withFiles(['avatar' => new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png')])
            ->post("/admin/users/{$this->clerk}/edit", [
                'username' => 'clerk',
                'email' => 'clerk@example.com',
                'role' => Role::User->value,
            ])
            ->assertStatus(302);
    }
}
