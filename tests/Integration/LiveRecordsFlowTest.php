<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Audit;
use App\Entities\User;
use App\Repositories\AuditRepository;
use App\Repositories\SentMailRepository;
use App\Repositories\SignInRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Message;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Mail, Sessions and Audit are written outside the admin, so each is live
 * because its repository publishes. Resolved from the container, since a
 * repository autowired past its ModuleChanges would publish nothing.
 */
#[CoversNothing]
final class LiveRecordsFlowTest extends TestCase
{
    private TestApp $app;
    private FakeBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->broadcaster = $this->app->get(FakeBroadcaster::class);
    }

    public function test_a_sent_message_is_told_to_the_mail_list(): void
    {
        $this->mail()->record($this->sent(), new DateTimeImmutable('@1790000000'));

        $this->broadcaster->assertPublished('module.mail', 'changed', times: 1);
    }

    public function test_pruning_mail_is_told_only_when_it_took_something(): void
    {
        $this->mail()->record($this->sent(), new DateTimeImmutable('@1790000000'));
        $this->mail()->prune(new DateTimeImmutable('@1700000000'));
        $this->broadcaster->assertPublished('module.mail', 'changed', times: 1);

        $this->assertSame(1, $this->mail()->prune(new DateTimeImmutable('@1800000000')));
        $this->broadcaster->assertPublished('module.mail', 'changed', times: 2);
    }

    public function test_a_sign_in_and_its_revocation_are_told_to_the_sessions_list(): void
    {
        $user = $this->user();

        $this->signIns()->create('one', $user, new DateTimeImmutable('@1790000000'));
        $this->broadcaster->assertPublished('module.sessions', 'changed', times: 1);

        $this->assertTrue($this->signIns()->revoke('one'));
        $this->broadcaster->assertPublished('module.sessions', 'changed', times: 2);

        $this->signIns()->create('two', $user, new DateTimeImmutable('@1790000000'));
        $this->signIns()->create('three', $user, new DateTimeImmutable('@1790000000'));
        $this->assertSame(1, $this->signIns()->revokeAll($user, except: 'three'));
        $this->broadcaster->assertPublished('module.sessions', 'changed', times: 5);
        // A sign-in's id is what a session holds to name it: it never travels.
        $this->assertSame([], $this->broadcaster->published(static fn ($e): bool => $e->topic === 'module.sessions' && $e->data !== ['id' => null]));
    }

    public function test_what_moved_no_session_is_not_told(): void
    {
        $user = $this->user();
        $this->signIns()->create('one', $user, new DateTimeImmutable('@1790000000'));
        $before = count($this->broadcaster->published());

        // Every request touches its sign-in; a live Sessions list must not
        // refetch on each of them.
        $this->signIns()->touch('one', new DateTimeImmutable('@1790000060'), '127.0.0.1', 'test');
        $this->assertFalse($this->signIns()->revoke('nobody'));
        $this->assertSame(0, $this->signIns()->revokeAll($user, except: 'one'));
        // Expired rows are already outside the sign-in window the list shows.
        $this->assertSame(1, $this->signIns()->prune(new DateTimeImmutable('@1800000000')));

        $this->assertCount($before, $this->broadcaster->published());
    }

    public function test_an_audit_row_is_told_to_the_audit_list(): void
    {
        $this->app->get(AuditRepository::class)->record(new Audit('users', '1', null, null, 1, 'boss', 'account.avatar_changed'));

        $this->broadcaster->assertPublished('module.audit', 'changed', times: 1);
    }

    private function mail(): SentMailRepository
    {
        return $this->app->get(SentMailRepository::class);
    }

    private function signIns(): SignInRepository
    {
        return $this->app->get(SignInRepository::class);
    }

    private function sent(): MessageSent
    {
        return new MessageSent(Message::make()->from('app@example.com')->to('ada@example.com')->text('Hi'), 'log');
    }

    private function user(): User
    {
        $user = $this->app->get(UserRepository::class)->byIdentifier($this->app->seed('ada'));
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }
}
