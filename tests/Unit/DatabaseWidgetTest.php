<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Widgets\DatabaseWidget;
use App\Config\DbConfig;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Widgets\Status;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Database\Testing\FakeConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one health card that stays in the application, because the connection it
 * describes is configured here. It keeps the contract the shipped cards hold
 * to: a probe that fails reports the failure rather than raising it.
 */
#[CoversClass(DatabaseWidget::class)]
final class DatabaseWidgetTest extends TestCase
{
    public function test_it_reports_the_server_it_reached(): void
    {
        $card = $this->widget(new PdoConnection(TestSchema::connect()))->present();

        $this->assertSame(Status::Ok, $card['status']);
        $this->assertSame('responding', $card['caption']);
        $this->assertSame(
            ['Driver', 'Version', 'Database', 'Size', 'Tables'],
            array_column($card['rows'], 'label'),
        );
    }

    public function test_a_connection_it_cannot_open_is_a_card_and_not_an_exception(): void
    {
        $card = $this->widget(FakeConnection::inMemory()->failAll())->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('is-down', $card['status']->tone());
        $this->assertSame('No answer', $card['headline']);
        $this->assertSame('Connection refused', $card['note']);
    }

    public function test_a_figure_the_grant_does_not_cover_is_left_off_rather_than_fatal(): void
    {
        // A grant over the application's own tables need not reach
        // information_schema, so the size and the count can fail on their own.
        $card = $this->widget(FakeConnection::inMemory()->failOn('pragma_page_count')->failOn('sqlite_master'))->present();

        $this->assertSame(Status::Ok, $card['status']);
        $this->assertSame(['Driver', 'Version', 'Database'], array_column($card['rows'], 'label'));
    }

    private function widget(ConnectionInterface $db): DatabaseWidget
    {
        return new DatabaseWidget($db, new DbConfig(
            driver: 'sqlite',
            host: 'db',
            port: 3306,
            database: 'hydra',
            username: 'hydra',
            password: '',
            charset: 'utf8mb4',
        ));
    }
}
