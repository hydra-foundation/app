<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\SentMailRepository;
use App\Tasks\PruneSentMail;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\PdoConnection;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Thirty days of sent mail, bodies and all, and no more. */
#[CoversClass(PruneSentMail::class)]
final class PruneSentMailTest extends TestCase
{
    public function test_mail_sent_over_thirty_days_ago_is_deleted(): void
    {
        $mail = new SentMailRepository(new PdoConnection(TestSchema::connect()));

        foreach (['2026-08-30 09:59:59', '2026-08-30 10:00:00', '2026-09-29 09:00:00'] as $at) {
            $mail->record(
                new MessageSent(Message::make()->from('a@example.com')->to('b@example.com')->text($at), 'smtp'),
                new DateTimeImmutable($at),
            );
        }

        (new PruneSentMail($mail, new FrozenClock('2026-09-29 10:00:00')))->run();

        $this->assertNull($mail->find(1));
        $this->assertNotNull($mail->find(2), 'sent exactly thirty days ago: kept');
        $this->assertNotNull($mail->find(3));
    }
}
