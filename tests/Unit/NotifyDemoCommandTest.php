<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\NotifyDemoCommand;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Admin\Testing\ArrayNotificationStore;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NotifyDemoCommand::class)]
final class NotifyDemoCommandTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        $store = new ArrayNotificationStore;

        yield 'notify:demo' => new NotifyDemoCommand(
            new UserRepository(new PdoConnection(TestSchema::connect())),
            new Notifier($store, new FrozenClock),
        );
    }

    public function test_it_rings_the_named_users_bell(): void
    {
        $pdo = TestSchema::connect();
        $pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('ada', 'ada@example.com', 'x')");
        $store = new ArrayNotificationStore;
        $output = new FakeOutput;

        $code = (new NotifyDemoCommand(new UserRepository(new PdoConnection($pdo)), new Notifier($store, new FrozenClock)))
            ->execute(new ArrayInput(['username' => 'ada']), $output);

        $this->assertSame(ExitCode::Success, $code);
        [$notice] = $store->latest(1, 1);
        $this->assertSame('A notice from notify:demo', $notice->notice->title);
        $this->assertSame('/admin', $notice->notice->url);
        $this->assertSame(['Notified ada. Their bell rings in every open admin tab.'], $output->linesOfKind('success'));
    }

    public function test_an_unknown_user_is_a_clear_failure(): void
    {
        $output = new FakeOutput;
        $store = new ArrayNotificationStore;

        $code = (new NotifyDemoCommand(new UserRepository(new PdoConnection(TestSchema::connect())), new Notifier($store, new FrozenClock)))
            ->execute(new ArrayInput(['username' => 'nobody']), $output);

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertSame(['No user is called "nobody".'], $output->linesOfKind('error'));
        $this->assertSame([], $store->latest(1, 10));
    }
}
