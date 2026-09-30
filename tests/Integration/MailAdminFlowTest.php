<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\SentMailRepository;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Http\Testing\Client;
use Hydra\Mail\Address;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Exceptions\TransportException;
use Hydra\Mail\Mailer;
use Hydra\Mail\Message;
use Hydra\Mail\Transports\ArrayTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * What went out, what it said, and whether the transport works: the answer to
 * "I never got the reset email" that used to be a trip through the logs.
 */
#[CoversNothing]
final class MailAdminFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;
    private SentMailRepository $mail;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
        $this->mail = $this->app->get(SentMailRepository::class);
    }

    public function test_sent_mail_is_listed_newest_first_with_its_transport(): void
    {
        $this->sent('ada@example.com', 'Reset your password', '-2 hours');
        $this->sent('bob@example.com', 'Verify your email', '-5 minutes');
        $this->login('boss');
        $body = $this->body('/admin/mail');

        $this->assertStringContainsString('<title>Mail · Admin</title>', $body);
        $this->assertMatchesRegularExpression('~>Verify your email</td>.*>Reset your password</td>~s', $body);
        $this->assertStringContainsString('>bob@example.com</td>', $body);
        $this->assertStringContainsString('>smtp</td>', $body);
        $this->assertMatchesRegularExpression('~<time[^>]*>5 minutes ago</time>~', $body);
    }

    public function test_the_entry_sits_under_administration_after_rate_limits(): void
    {
        $this->login('boss');

        $this->assertMatchesRegularExpression('~href="/admin/rate-limits".*?href="/admin/mail".*?href="/admin/files"~s', $this->body('/admin/mail'));
    }

    public function test_search_finds_mail_by_recipient_and_by_subject(): void
    {
        $this->sent('ada@example.com', 'Reset your password');
        $this->sent('bob@example.com', 'Verify your email');
        $this->login('boss');

        $byRecipient = $this->body('/admin/mail?q=ada%40');
        $this->assertStringContainsString('>Reset your password</td>', $byRecipient);
        $this->assertStringNotContainsString('>Verify your email</td>', $byRecipient);

        $bySubject = $this->body('/admin/mail?q=verify');
        $this->assertStringContainsString('>bob@example.com</td>', $bySubject);
        $this->assertStringNotContainsString('>ada@example.com</td>', $bySubject);
    }

    public function test_the_preview_shows_the_whole_message(): void
    {
        $this->mail->record(new MessageSent(
            Message::make()
                ->from('app@example.com', 'The App')
                ->to('ada@example.com', 'Ada')
                ->cc('cc@example.com')
                ->bcc('hidden@example.com')
                ->subject('Reset your password')
                ->text("Hello Ada,\n\nhttps://x.test/r?token=abc"),
            'smtp',
        ), new DateTimeImmutable('-1 minute'));
        $this->login('boss');
        $body = $this->body('/admin/mail/1');

        $this->assertStringContainsString('The App &lt;app@example.com&gt;', $body);
        $this->assertStringContainsString('Ada &lt;ada@example.com&gt;', $body);
        $this->assertStringContainsString('cc@example.com', $body);
        $this->assertStringContainsString('hidden@example.com', $body);
        $this->assertStringContainsString("<pre class=\"mb-0 small\">Hello Ada,\n\nhttps://x.test/r?token=abc</pre>", $body);
    }

    public function test_an_html_body_is_shown_as_escaped_source_never_rendered(): void
    {
        $this->mail->record(new MessageSent(
            Message::make()->from('app@example.com')->to('ada@example.com')->subject('Hi')
                ->text('Hi')->html('<p>Hi</p><script>alert(1)</script>'),
            'smtp',
        ), new DateTimeImmutable);
        $this->login('boss');
        $body = $this->body('/admin/mail/1');

        $this->assertStringContainsString('&lt;p&gt;Hi&lt;/p&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function test_a_message_with_no_html_says_so(): void
    {
        $this->sent('ada@example.com', 'Plain');
        $this->login('boss');

        $this->assertMatchesRegularExpression('~HTML</dt>\s*<dd[^>]*>\s*None\s*</dd>~', $this->body('/admin/mail/1'));
    }

    public function test_a_pruned_message_is_gone(): void
    {
        $this->login('boss');

        $this->http->get('/admin/mail/42')->assertStatus(404)->assertSee('That message is no longer in the log.');
    }

    public function test_the_log_cannot_be_edited(): void
    {
        $this->sent('ada@example.com', 'Reset your password');
        $this->login('boss');
        $body = $this->body('/admin/mail');

        $this->assertStringNotContainsString('/admin/mail/1/delete', $body);
        $this->assertStringNotContainsString('/admin/mail/1/edit', $body);
        $this->http->post('/admin/mail/1/delete')->assertStatus(404);
    }

    public function test_only_an_admin_may_look(): void
    {
        $this->sent('ada@example.com', 'Reset your password');
        $this->login('clerk');

        $this->http->get('/admin/mail')->assertStatus(403);
        $this->http->get('/admin/mail/1')->assertStatus(403);
    }

    public function test_the_list_offers_a_test_email(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/mail');

        $this->assertStringContainsString('hx-post="/admin/mail/test"', $body);
        $this->assertStringContainsString('>Send a test email</button>', $body);
    }

    public function test_a_test_email_goes_to_the_admin_at_once_and_is_logged(): void
    {
        $transport = $this->realMailer(new ArrayTransport);
        $this->login('boss');

        $this->frame()->post('/admin/mail/test')->assertOk()->assertSee('Test email sent to boss@example.com.');

        $this->assertCount(1, $transport->messages());
        $sent = $transport->messages()[0];
        $this->assertTrue($sent->isFor('boss@example.com'));
        $this->assertSame('Test email from Hydra', $sent->getSubject());
        $this->assertStringContainsString('boss', (string) $sent->getText());
        $this->assertSame(0, $this->app->queued(), 'sent in the request, not queued');

        $rows = $this->app->db()->select('SELECT to_addresses, subject FROM sent_mail');
        $this->assertSame([['to_addresses' => 'boss <boss@example.com>', 'subject' => 'Test email from Hydra']], $rows);
    }

    public function test_a_transport_that_fails_says_why_and_logs_nothing(): void
    {
        $this->realMailer(new class implements TransportInterface {
            public function send(Message $message): void
            {
                throw new TransportException('The SMTP server refused the credentials: 535 Authentication failed');
            }
        });
        $this->login('boss');

        $this->frame()->post('/admin/mail/test')
            ->assertStatus(422)
            ->assertSee('The SMTP server refused the credentials: 535 Authentication failed');

        $this->assertSame([], $this->app->db()->select('SELECT id FROM sent_mail'));
    }

    public function test_a_mailer_with_no_sender_configured_says_so(): void
    {
        $this->app->container()->instance(MailerInterface::class, new Mailer(new ArrayTransport));
        $this->login('boss');

        $this->frame()->post('/admin/mail/test')
            ->assertStatus(422)
            ->assertSee('The message has no sender, and MAIL_FROM_ADDRESS is not set.');
    }

    public function test_only_an_admin_may_send_a_test(): void
    {
        $transport = $this->realMailer(new ArrayTransport);
        $this->login('clerk');

        $this->http->post('/admin/mail/test')->assertStatus(403);
        $this->assertSame([], $transport->messages());
    }

    /**
     * The suite's mailer is a fake that sends and announces nothing; the test
     * button is about the real one, so it gets the real one over $transport.
     *
     * @template T of TransportInterface
     * @param T $transport
     * @return T
     */
    private function realMailer(TransportInterface $transport): TransportInterface
    {
        $this->app->container()->instance(MailerInterface::class, new Mailer(
            $transport,
            new Address('app@example.com'),
            $this->app->get(EventDispatcherInterface::class),
            'array',
        ));

        return $transport;
    }

    private function frame(): Client
    {
        return $this->http->htmx('div#admin-frame');
    }

    private function sent(string $to, string $subject, string $when = 'now'): void
    {
        $this->mail->record(
            new MessageSent(Message::make()->from('app@example.com')->to($to)->subject($subject)->text('Body of ' . $subject), 'smtp'),
            new DateTimeImmutable($when),
        );
    }

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
