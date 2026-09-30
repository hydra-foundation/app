<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Listeners\RecordSentMailListener;
use App\Repositories\SentMailRepository;
use App\Tests\Support\TestApp;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\PdoConnection;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Mail\Address;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Mailer;
use Hydra\Mail\Message;
use Hydra\Mail\Transports\ArrayTransport;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The app's record of what went out. The suite fakes the mailer, which sends
 * nothing and so announces nothing; these build the real one on purpose.
 */
#[CoversClass(RecordSentMailListener::class)]
final class RecordSentMailListenerTest extends TestCase
{
    public function test_a_message_the_real_mailer_sends_is_recorded_once_at_the_clocks_time(): void
    {
        $app = TestApp::boot();
        $app->container()->instance(ClockInterface::class, new FrozenClock('@1790000000'));
        $transport = new ArrayTransport;
        $mailer = new Mailer($transport, new Address('app@example.com'), $app->get(EventDispatcherInterface::class), 'array');

        $mailer->send(Message::make()->to('ada@example.com')->subject('Hello')->text('Hi Ada'));

        $rows = $app->db()->select('SELECT * FROM sent_mail');
        $this->assertCount(1, $rows);
        $this->assertSame('ada@example.com', $rows[0]['to_addresses']);
        $this->assertSame('app@example.com', $rows[0]['from_address']);
        $this->assertSame('Hello', $rows[0]['subject']);
        $this->assertSame('array', $rows[0]['transport']);
        $this->assertSame(1790000000, (int) $rows[0]['sent_at']);
    }

    public function test_a_record_that_cannot_be_written_is_logged_and_the_send_still_stands(): void
    {
        // No sent_mail table: every insert fails.
        $broken = new SentMailRepository(new PdoConnection(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])));
        $log = new CapturingLogger;
        $listener = new RecordSentMailListener($broken, new FrozenClock('@1790000000'), $log);

        $listener(new MessageSent(Message::make()->from('a@example.com')->to('b@example.com')->text('x'), 'smtp'));

        $this->assertCount(1, $log->records());
        $this->assertSame('error', $log->records()[0]['level']);
        $this->assertStringStartsWith('Could not record sent mail: ', $log->records()[0]['message']);
        $this->assertArrayHasKey('exception', $log->records()[0]['context']);
    }
}
