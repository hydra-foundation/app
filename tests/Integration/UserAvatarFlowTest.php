<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use Hydra\Filesystem\Disks;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * An admin giving a user a picture from the Users module, and the picture
 * going with the user when the user goes.
 */
#[CoversNothing]
final class UserAvatarFlowTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private TestApp $app;
    private int $boss;
    private int $clerk;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->boss = $this->app->seed('boss', Role::Admin);
        $this->clerk = $this->app->seed('clerk', Role::User);
        $this->app->login('boss')->assertStatus(302);
    }

    public function test_the_edit_form_offers_a_picture_upload(): void
    {
        $body = $this->app->http()->get("/admin/users/{$this->clerk}/edit")->assertOk()->body();

        $this->assertStringContainsString('type="file"', $body);
        $this->assertStringContainsString('name="avatar"', $body);
        $this->assertStringContainsString('hx-encoding="multipart/form-data"', $body);
    }

    public function test_an_admin_uploads_a_users_avatar(): void
    {
        $this->save($this->png())->assertStatus(302);

        $avatar = $this->avatar();
        $this->assertNotNull($avatar);
        $this->assertStringStartsWith('private:avatars/', $avatar);
        $this->assertTrue($this->stored($avatar));
    }

    public function test_the_list_and_the_user_screen_show_it(): void
    {
        $this->save($this->png());
        $url = '/admin/file?key=' . rawurlencode((string) $this->avatar());

        $this->assertStringContainsString(htmlspecialchars($url), $this->app->http()->get('/admin/users')->assertOk()->body());
        $this->assertStringContainsString(htmlspecialchars($url), $this->app->http()->get("/admin/users/{$this->clerk}")->assertOk()->body());
        $this->app->http()->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_user_with_no_avatar_is_shown_the_person_icon(): void
    {
        $this->assertStringContainsString('bi-person-circle', $this->app->http()->get('/admin/users')->assertOk()->body());
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $this->save(new UploadedFile(Stream::create("<?php echo 1;\n"), 14, UPLOAD_ERR_OK, 'me.png', 'image/png'))
            ->assertStatus(422);

        $this->assertNull($this->avatar());
    }

    public function test_removing_it_clears_the_column_and_the_file(): void
    {
        $this->save($this->png());
        $avatar = (string) $this->avatar();

        $this->save(null, ['avatar_remove' => '1'])->assertStatus(302);

        $this->assertNull($this->avatar());
        $this->assertFalse($this->stored($avatar));
    }

    public function test_the_name_it_was_uploaded_under_is_kept_and_downloaded_under(): void
    {
        $this->save($this->png('Clerk Portrait.png'))->assertStatus(302);

        $this->assertSame('Clerk Portrait.png', $this->avatarName());

        $url = '/admin/file?key=' . rawurlencode((string) $this->avatar()) . '&name=Clerk%20Portrait.png';
        $this->assertStringContainsString(
            htmlspecialchars($url),
            $this->app->http()->get("/admin/users/{$this->clerk}/edit")->assertOk()->body(),
        );
        $this->assertStringContainsString(
            "filename*=UTF-8''Clerk%20Portrait.png",
            $this->app->http()->get($url)->assertOk()->header('Content-Disposition'),
        );
    }

    public function test_removing_it_clears_its_name_too(): void
    {
        $this->save($this->png('Clerk Portrait.png'));

        $this->save(null, ['avatar_remove' => '1'])->assertStatus(302);

        $this->assertNull($this->avatarName());
    }

    public function test_deleting_the_user_deletes_the_avatar(): void
    {
        $this->save($this->png());
        $avatar = (string) $this->avatar();

        $this->app->http()->post("/admin/users/{$this->clerk}/delete")->assertStatus(302);

        $this->assertFalse($this->stored($avatar));
    }

    public function test_someone_signed_out_cannot_fetch_it(): void
    {
        $this->save($this->png());
        $url = '/admin/file?key=' . rawurlencode((string) $this->avatar());

        $this->app->http()->post('/logout');

        $this->app->http()->get($url)->assertStatus(302);
    }

    public function test_an_admin_giving_themselves_a_picture_sees_it_in_the_chrome_at_once(): void
    {
        // The save renders the list in the same request, from a guard that
        // read the row before the write, and the account slot sits outside the
        // frame: both have to be put right for the picture to show.
        $body = $this->app->http()->withFiles(['avatar' => $this->png()])->post("/admin/users/{$this->boss}/edit", [
            'username' => 'boss',
            'email' => 'boss@example.com',
            'role' => Role::Admin->value,
        ], ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame'])->assertOk()->body();

        $avatar = $this->app->get(UserRepository::class)->byIdentifier($this->boss)?->avatar;
        $this->assertNotNull($avatar);
        $url = htmlspecialchars('/admin/file?key=' . rawurlencode($avatar));

        foreach (['topbar', 'sidebar'] as $place) {
            $this->assertMatchesRegularExpression('~id="admin-account-' . $place . '"[^>]*hx-swap-oob="true"[^>]*>.*?' . preg_quote($url, '~') . '~s', $body);
        }
    }

    /** @param array<string, string> $extra */
    private function save(?UploadedFile $file, array $extra = []): \Hydra\Http\Testing\TestResponse
    {
        $http = $file === null ? $this->app->http() : $this->app->http()->withFiles(['avatar' => $file]);

        return $http->post("/admin/users/{$this->clerk}/edit", [
            'username' => 'clerk',
            'email' => 'clerk@example.com',
            'role' => Role::User->value,
            ...$extra,
        ]);
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
