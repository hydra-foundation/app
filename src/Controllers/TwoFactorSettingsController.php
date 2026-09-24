<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Presenters\SecurityPresenter;
use App\Entities\Audit;
use App\Entities\User;
use App\Jobs\SendTwoFactorNotice;
use App\Repositories\AuditRepository;
use App\Repositories\TwoFactorRepository;
use App\Security\CurrentPassword;
use App\Security\TwoFactorSetup;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Hydra\Auth\RecoveryCodes;
use Hydra\Auth\Totp;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\ParsedBody;
use Hydra\Http\Status;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Security settings screen: turning the second factor on, off, and
 * replacing its recovery codes. Anything that changes it takes the password
 * and a code, so an unattended signed-in browser cannot.
 */
final class TwoFactorSettingsController
{
    /** The same ceiling the login form holds a password to. */
    private const MAX_PASSWORD = 4096;

    private const MAX_CODE = 64;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GuardInterface $guard,
        private readonly SecurityPresenter $presenter,
        private readonly TwoFactorRepository $store,
        private readonly TwoFactorSetup $setup,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly CurrentPassword $currentPassword,
        private readonly Validator $validator,
        private readonly AuditRepository $audit,
        private readonly QueueInterface $queue,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function save(Request $request): Response
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        $input = ParsedBody::fromRequest($request);
        $enabled = $this->store->secret($user) !== null;

        return match ([$input->string('form'), $enabled]) {
            ['start', false] => $this->start($request),
            ['cancel', false] => $this->cancel($request),
            ['confirm', false] => $this->confirm($request, $user, $input),
            ['regenerate', true] => $this->regenerate($request, $user, $input),
            ['disable', true] => $this->disable($request, $user, $input),
            // A second tab, or a form left open across a change.
            default => $this->screen($request, notice: Notice::failure('That form is out of date. Here is where things stand now.')),
        };
    }

    private function start(Request $request): Response
    {
        $this->setup->start();

        return $this->screen($request);
    }

    private function cancel(Request $request): Response
    {
        $this->setup->forget();

        return $this->screen($request);
    }

    private function confirm(Request $request, User $user, ParsedBody $input): Response
    {
        $secret = $this->setup->secret();

        if ($secret === null) {
            return $this->screen($request, notice: Notice::failure('That setup has expired. Start again.'));
        }

        [$code, $errors] = $this->validate($user, $input, 'Enter the code your app shows.');
        $step = null;

        if ($errors === []) {
            $step = $this->totp->verify($secret, $code);
            $errors = $step === null ? ['code' => 'That code does not match. Check the app has the key above.'] : [];
        }

        if ($errors !== []) {
            return $this->screen($request, $errors, 'confirm', status: Status::UnprocessableEntity);
        }

        ['codes' => $codes, 'hashes' => $hashes] = $this->recoveryCodes->generate();
        $this->store->enable($user, $secret, $hashes);
        // The code that confirmed it cannot then sign in a second time.
        $this->store->claimStep($user, (int) $step);
        $this->setup->forget();

        $this->record($user, 'account.two_factor_enabled');
        $this->notify($user, SendTwoFactorNotice::ENABLED);

        return $this->screen($request, codes: $codes, notice: Notice::success('Two-factor sign in is on.'));
    }

    private function regenerate(Request $request, User $user, ParsedBody $input): Response
    {
        $errors = $this->prove($user, $input);

        if ($errors !== []) {
            return $this->screen($request, $errors, 'regenerate', status: Status::UnprocessableEntity);
        }

        ['codes' => $codes, 'hashes' => $hashes] = $this->recoveryCodes->generate();
        $this->store->replaceRecoveryHashes($user, $hashes);

        $this->record($user, 'account.recovery_codes_regenerated');

        return $this->screen($request, codes: $codes, notice: Notice::success('New recovery codes. The old ones no longer work.'));
    }

    private function disable(Request $request, User $user, ParsedBody $input): Response
    {
        $errors = $this->prove($user, $input);

        if ($errors !== []) {
            return $this->screen($request, $errors, 'disable', status: Status::UnprocessableEntity);
        }

        $this->store->disable($user);

        $this->record($user, 'account.two_factor_disabled');
        $this->notify($user, SendTwoFactorNotice::DISABLED);

        return $this->screen($request, notice: Notice::success('Two-factor sign in is off.'));
    }

    /**
     * The password, then a code from the app or a recovery code, which is
     * spent: someone who lost the phone signs in with one and can still get here.
     *
     * @return array<string, string>
     */
    private function prove(User $user, ParsedBody $input): array
    {
        [$code, $errors] = $this->validate($user, $input, 'Enter a code from your app, or a recovery code.');

        if ($errors !== []) {
            return $errors;
        }

        $secret = $this->store->secret($user) ?? '';
        $step = $this->totp->verify($secret, $code);

        if ($step !== null && $this->store->claimStep($user, $step)) {
            return [];
        }

        $hashes = $this->store->recoveryHashes($user);
        $left = $this->recoveryCodes->redeem($code, $hashes);
        $spent = $left === null ? [] : array_values(array_diff($hashes, $left));

        if ($spent !== [] && $this->store->spendRecoveryHash($user, $spent[0])) {
            $this->events->dispatch(new RecoveryCodeUsed($user, count($left ?? [])));

            return [];
        }

        return ['code' => 'That code is not valid, or has been used.'];
    }

    /**
     * Shape first, then the password: a code is only looked at once the
     * password is right, so the password's budget guards both.
     *
     * @return array{string, array<string, string>}
     */
    private function validate(User $user, ParsedBody $input, string $missingCode): array
    {
        $data = [
            'code' => trim($input->string('code')),
            'current_password' => $input->string('current_password'),
        ];

        $result = $this->validator->validate($data, [
            'code' => [new Required($missingCode), new MaxLength(self::MAX_CODE, $missingCode)],
            'current_password' => [new Required('Enter your current password.'), new MaxLength(self::MAX_PASSWORD)],
        ]);

        if (!$result->passes()) {
            return [$data['code'], $result->errors()];
        }

        if (!$this->currentPassword->matches($user, $data['current_password'])) {
            return [$data['code'], ['current_password' => 'That is not your current password.']];
        }

        return [$data['code'], []];
    }

    private function notify(User $user, string $notice): void
    {
        try {
            $this->queue->push(SendTwoFactorNotice::class, ['user' => $user->id, 'notice' => $notice]);
        } catch (Throwable $e) {
            $this->logger->error('Could not queue two-factor notice: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /** Filed beside the account's other changes, and swallowed like them: the change has happened. */
    private function record(User $user, string $message): void
    {
        try {
            $this->audit->record(new Audit('users', (string) $user->id, null, null, $user->id, $user->username, $message));
        } catch (Throwable $e) {
            $this->logger->warning('Could not record audit: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param list<string>|null $codes
     */
    private function screen(
        Request $request,
        array $errors = [],
        string $form = '',
        ?array $codes = null,
        ?Notice $notice = null,
        int|Status $status = Status::Ok,
    ): Response {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Security', notice: $notice),
            'admin/settings/security',
            $this->presenter->present($errors, $form, $codes),
            status: $status,
        );
    }
}
