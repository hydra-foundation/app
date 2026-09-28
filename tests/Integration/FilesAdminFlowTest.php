<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Admin\Files\FileId;
use Hydra\Filesystem\Disks;
use Hydra\Filesystem\FilesystemConfig;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * An admin looking over every stored file: which one belongs to whom, which
 * belong to nobody, and clearing those out without touching the rest.
 */
#[CoversNothing]
final class FilesAdminFlowTest extends TestCase
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

    public function test_an_uploaded_avatar_is_listed_as_in_use_under_its_kept_name(): void
    {
        $this->uploadAvatar('Clerk Portrait.png');

        $body = $this->app->http()->get('/admin/files')->assertOk()->body();

        $this->assertStringContainsString('Clerk Portrait.png', $body);
        $this->assertStringContainsString('In use', $body);
        $this->assertStringContainsString('1 file · ', $body);
    }

    public function test_a_files_screen_links_to_the_user_it_belongs_to(): void
    {
        $avatar = $this->uploadAvatar('Clerk Portrait.png');

        $body = $this->app->http()->get('/admin/files/' . FileId::of($avatar))->assertOk()->body();

        $this->assertStringContainsString('href="/admin/users/' . $this->clerk . '"', $body);
        $this->assertStringContainsString('Users #' . $this->clerk . ' · avatar', $body);
    }

    public function test_a_stray_file_a_day_old_is_an_orphan_and_delete_orphans_clears_only_it(): void
    {
        $avatar = $this->uploadAvatar('Clerk Portrait.png');
        $this->age($avatar, 3 * 86_400);
        $stray = $this->stray(2 * 86_400);

        $orphans = $this->app->http()->get('/admin/files?status=orphan')->assertOk()->body();
        $this->assertStringContainsString(basename($stray), $orphans);
        $this->assertStringNotContainsString('Clerk Portrait.png', $orphans);

        $this->app->http()->post('/admin/files/delete-orphans')->assertStatus(302);

        $this->assertFalse($this->stored($stray));
        $this->assertTrue($this->stored($avatar));
    }

    public function test_a_stray_file_stored_today_is_not_offered_for_deletion(): void
    {
        $stray = $this->stray(60);

        $this->app->http()->post('/admin/files/delete-orphans')->assertStatus(302);

        $this->assertTrue($this->stored($stray));
    }

    public function test_deleting_a_file_in_use_is_refused_and_the_file_stays(): void
    {
        $avatar = $this->uploadAvatar('Clerk Portrait.png');

        $this->app->http()->post('/admin/files/' . FileId::of($avatar) . '/delete')->assertStatus(422);

        $this->assertTrue($this->stored($avatar));
    }

    public function test_an_orphan_can_be_deleted_from_its_screen(): void
    {
        $stray = $this->stray(2 * 86_400);

        $this->app->http()->post('/admin/files/' . FileId::of($stray) . '/delete')->assertStatus(302);

        $this->assertFalse($this->stored($stray));
    }

    public function test_the_dashboard_card_counts_each_disk_and_links_to_the_orphans(): void
    {
        $this->uploadAvatar('Clerk Portrait.png');
        $this->stray(2 * 86_400);

        $card = $this->app->http()->get('/admin/dashboard/w/files')->assertOk()->body();

        $this->assertStringContainsString('Private', $card);
        $this->assertStringContainsString('2 files', $card);
        $this->assertStringContainsString('href="/admin/files?status=orphan"', $card);
        $this->assertStringContainsString('1 orphan', $card);
        $this->assertStringContainsString('Clerk Portrait.png', $card);
    }

    public function test_someone_who_is_not_an_admin_is_kept_out(): void
    {
        $this->app->http()->post('/logout');
        $this->app->login('clerk')->assertStatus(302);

        $this->app->http()->get('/admin/files')->assertStatus(403);
    }

    private function uploadAvatar(string $name): string
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

        return (string) $this->app->get(UserRepository::class)->byIdentifier($this->clerk)?->avatar;
    }

    /** A file on the private disk that no row was ever written for, $age seconds old. */
    private function stray(int $age): string
    {
        $disks = $this->app->get(Disks::class);
        $qualified = $disks->qualify(Disks::PRIVATE, $disks->private()->put('avatars', Stream::create(base64_decode(self::PNG))));
        $this->age($qualified, $age);

        return $qualified;
    }

    private function age(string $qualified, int $seconds): void
    {
        $root = $this->app->get(FilesystemConfig::class)->privateRoot;
        touch($root . '/' . substr($qualified, strlen('private:')), time() - $seconds);
        clearstatcache();
    }

    private function stored(string $qualified): bool
    {
        [$disk, $key] = $this->app->get(Disks::class)->locate($qualified);

        return $disk->exists($key);
    }
}
