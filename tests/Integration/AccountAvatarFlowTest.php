<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Filesystem\Disks;
use Hydra\Http\Testing\TestResponse;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Anyone signed in setting their own picture from Settings › Account. A
 * standard user, on purpose: this is the one place they can change it.
 */
#[CoversNothing]
final class AccountAvatarFlowTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private TestApp $app;
    private int $clerk;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->clerk = $this->app->seed('clerk', Role::User);
        $this->app->login('clerk')->assertStatus(302);
    }

    public function test_the_account_screen_offers_an_avatar_upload(): void
    {
        $body = $this->app->http()->get('/admin/settings/account')->assertOk()->body();

        $this->assertStringContainsString('id="avatar-form"', $body);
        $this->assertStringContainsString('hx-encoding="multipart/form-data"', $body);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp,image/gif"', $body);
        $this->assertStringContainsString('bi-person-circle', $body);
    }

    public function test_a_user_uploads_their_own_avatar(): void
    {
        $this->upload($this->png())->assertOk();

        $avatar = $this->avatar();
        $this->assertNotNull($avatar);
        $this->assertStringStartsWith('private:avatars/', $avatar);
        $this->assertTrue($this->stored($avatar));
    }

    public function test_the_account_screen_shows_it_afterwards_and_serves_it(): void
    {
        $this->upload($this->png());
        $url = '/admin/file?key=' . rawurlencode((string) $this->avatar());

        $this->assertStringContainsString(htmlspecialchars($url), $this->app->http()->get('/admin/settings/account')->body());
        $this->app->http()->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_replacing_it_deletes_the_old_file(): void
    {
        $this->upload($this->png());
        $old = (string) $this->avatar();

        $this->upload($this->png());

        $this->assertNotSame($old, $this->avatar());
        $this->assertFalse($this->stored($old));
    }

    public function test_removing_it_clears_it_and_deletes_the_file(): void
    {
        $this->upload($this->png());
        $old = (string) $this->avatar();

        $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar', 'avatar_remove' => '1'])->assertOk();

        $this->assertNull($this->avatar());
        $this->assertFalse($this->stored($old));
    }

    public function test_setting_and_removing_it_are_audited(): void
    {
        $this->upload($this->png())->assertOk();
        $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar', 'avatar_remove' => '1'])->assertOk();

        $rows = $this->audits();

        $this->assertSame(['account.avatar_changed', 'account.avatar_removed'], array_column($rows, 'message'));
        $this->assertSame(['users'], array_values(array_unique(array_column($rows, 'module'))));
        $this->assertSame([(string) $this->clerk], array_values(array_unique(array_column($rows, 'table_id'))));
        $this->assertSame(['clerk'], array_values(array_unique(array_column($rows, 'username'))));
    }

    public function test_removing_a_picture_that_is_not_there_is_not_audited(): void
    {
        $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar', 'avatar_remove' => '1'])->assertOk();

        $this->assertSame([], $this->audits());
    }

    public function test_a_refused_upload_is_not_audited(): void
    {
        $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar'])->assertStatus(422);

        $this->assertSame([], $this->audits());
    }

    public function test_the_name_it_was_uploaded_under_is_kept(): void
    {
        $this->upload($this->png('Holiday.png'))->assertOk();

        $this->assertSame('Holiday.png', $this->avatarName());
    }

    public function test_removing_it_clears_its_name_too(): void
    {
        $this->upload($this->png('Holiday.png'));

        $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar', 'avatar_remove' => '1'])->assertOk();

        $this->assertNull($this->avatarName());
    }

    public function test_something_that_is_not_an_image_is_refused_with_a_reason(): void
    {
        $script = new UploadedFile(Stream::create("<?php echo 1;\n"), 14, UPLOAD_ERR_OK, 'me.png', 'image/png');

        $response = $this->upload($script)->assertStatus(422);

        $this->assertStringContainsString('The file must be a JPEG, PNG, WebP or GIF image.', $response->body());
        $this->assertNull($this->avatar());
    }

    public function test_uploading_nothing_asks_for_a_file(): void
    {
        $response = $this->app->http()->post('/admin/settings/account', ['intent' => 'avatar'])->assertStatus(422);

        $this->assertStringContainsString('Choose a picture to upload.', $response->body());
    }

    public function test_the_other_account_forms_still_work(): void
    {
        $this->app->http()->post('/admin/settings/account', [
            'current_password' => TestApp::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertNull($this->avatar());
    }

    public function test_the_admin_chrome_shows_who_is_signed_in_with_the_icon_until_there_is_a_picture(): void
    {
        $body = $this->app->http()->get('/admin/settings')->assertOk()->body();

        $this->assertStringContainsString('<div class="admin-account">', $body);
        $this->assertStringContainsString('href="/admin/settings/account"', $body);
        $this->assertStringContainsString('<span>clerk</span>', $body);
        $this->assertStringContainsString('bi-person-circle', $body);
    }

    public function test_the_admin_chrome_shows_the_picture_once_there_is_one(): void
    {
        $this->upload($this->png());
        $url = '/admin/file?key=' . rawurlencode((string) $this->avatar());

        $body = $this->app->http()->get('/admin/settings')->assertOk()->body();
        $account = substr($body, (int) strpos($body, '<div class="admin-account">'), 400);

        $this->assertStringContainsString(htmlspecialchars($url), $account);
    }

    /** @return list<array<string, mixed>> */
    private function audits(): array
    {
        return $this->app->db()->select('SELECT * FROM audit ORDER BY id');
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->app->http()->withFiles(['avatar' => $file])->post('/admin/settings/account', ['intent' => 'avatar']);
    }

    private function avatar(): ?string
    {
        return $this->app->get(UserRepository::class)->byIdentifier($this->clerk)?->avatar;
    }

    private function avatarName(): ?string
    {
        return $this->app->get(UserRepository::class)->byIdentifier($this->clerk)?->avatarName;
    }

    private function stored(string $qualified): bool
    {
        [$disk, $key] = $this->app->get(Disks::class)->locate($qualified);

        return $disk->exists($key);
    }

    private function png(string $name = 'me.png'): UploadedFile
    {
        $bytes = base64_decode(self::PNG);

        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png');
    }
}
