<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\SentMailRepository;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use Hydra\Database\PdoConnection;
use Hydra\Mail\Events\MessageSent;
use Hydra\Mail\Message;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SentMailRepository::class)]
final class SentMailRepositoryTest extends TestCase
{
    private PDO $pdo;

    private SentMailRepository $mail;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->mail = new SentMailRepository(new PdoConnection($this->pdo));
    }

    public function test_every_part_of_the_message_is_recorded(): void
    {
        $message = Message::make()
            ->from('app@example.com', 'The App')
            ->to('ada@example.com', 'Ada Lovelace')
            ->to('bob@example.com')
            ->cc('cc@example.com', 'Cee')
            ->bcc('hidden@example.com')
            ->subject('Reset your password')
            ->text('https://x.test/r?token=abc')
            ->html('<p>Reset</p>');

        $this->mail->record(new MessageSent($message, 'smtp'), new DateTimeImmutable('@1790000000'));

        $row = $this->mail->find(1);
        $this->assertNotNull($row);
        $this->assertSame(1790000000, (int) $row['sent_at']);
        $this->assertSame('smtp', $row['transport']);
        $this->assertSame('The App <app@example.com>', $row['from_address']);
        $this->assertSame('Ada Lovelace <ada@example.com>, bob@example.com', $row['to_addresses']);
        $this->assertSame('Cee <cc@example.com>', $row['cc_addresses']);
        $this->assertSame('hidden@example.com', $row['bcc_addresses']);
        $this->assertSame('Reset your password', $row['subject']);
        $this->assertSame('https://x.test/r?token=abc', $row['text_body']);
        $this->assertSame('<p>Reset</p>', $row['html_body']);
    }

    public function test_a_message_with_no_html_and_no_copies_records_them_as_nothing(): void
    {
        $this->mail->record(
            new MessageSent(Message::make()->from('app@example.com')->to('ada@example.com')->text('Hi'), 'log'),
            new DateTimeImmutable('@1790000000'),
        );

        $row = $this->mail->find(1);
        $this->assertNotNull($row);
        $this->assertNull($row['html_body']);
        $this->assertSame('', $row['cc_addresses']);
        $this->assertSame('', $row['bcc_addresses']);
        $this->assertSame('', $row['subject']);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->assertNull($this->mail->find(42));
    }

    public function test_a_long_transport_name_is_clipped_to_the_column(): void
    {
        $this->mail->record(
            new MessageSent(Message::make()->from('a@example.com')->to('b@example.com')->text('x'), str_repeat('t', 40)),
            new DateTimeImmutable('@1790000000'),
        );

        $this->assertSame(str_repeat('t', 16), $this->mail->find(1)['transport'] ?? null);
    }

    public function test_prune_deletes_mail_sent_before_the_cutoff_and_keeps_the_rest(): void
    {
        foreach ([999, 1000, 1001] as $at) {
            $this->mail->record(
                new MessageSent(Message::make()->from('a@example.com')->to('b@example.com')->text((string) $at), 'array'),
                new DateTimeImmutable('@' . $at),
            );
        }

        $this->assertSame(1, $this->mail->prune(new DateTimeImmutable('@1000')));
        $this->assertNull($this->mail->find(1));
        $this->assertNotNull($this->mail->find(2), 'sent exactly at the cutoff: kept');
        $this->assertNotNull($this->mail->find(3));
    }
}
