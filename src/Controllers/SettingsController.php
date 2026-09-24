<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Presenters\AccountPresenter;
use App\Admin\Presenters\AppearancePresenter;
use App\Admin\Presenters\RegionalPresenter;
use App\Entities\Audit;
use App\Entities\User;
use App\Jobs\SendEmailChangeLink;
use App\Repositories\AuditRepository;
use App\Repositories\PreferenceRepository;
use App\Repositories\UserRepository;
use App\View\Themes;
use App\View\Timezones;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\ParsedBody;
use Hydra\Http\Status;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Validation\Rules\Confirmed;
use Hydra\Validation\Rules\Email;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The write half of the settings module. Its screens are declared there and
 * routed by the scanner like any other, so this is an ordinary action that
 * happens to render back into the admin's own chrome.
 */
final class SettingsController
{
    /** The same ceiling the login form holds a password to. */
    private const MAX_PASSWORD = 4096;

    private const PASSWORD_CHECKS = 5;

    private const PASSWORD_WINDOW = 3600;

    /** The column's width. */
    private const MAX_EMAIL = 255;

    private const EMAIL_FORM = 'email';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
        private readonly Themes $themes,
        private readonly Timezones $timezones,
        private readonly AppearancePresenter $presenter,
        private readonly RegionalPresenter $regionalPresenter,
        private readonly AccountPresenter $accountPresenter,
        private readonly UserRepository $users,
        private readonly HasherInterface $hasher,
        private readonly RateLimiter $limiter,
        private readonly Validator $validator,
        private readonly AuditRepository $audit,
        private readonly QueueInterface $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function saveAccount(Request $request): Response
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        // Two forms on one screen, and a screen submits to one handler.
        $input = ParsedBody::fromRequest($request);

        return $input->string('form') === self::EMAIL_FORM
            ? $this->changeEmail($request, $user, $input)
            : $this->changePassword($request, $user, $input);
    }

    private function changePassword(Request $request, User $user, ParsedBody $input): Response
    {
        $data = [
            'current_password' => $input->string('current_password'),
            'password' => $input->string('password'),
            'password_confirmation' => $input->string('password_confirmation'),
        ];

        $result = $this->validator->validate($data, [
            'current_password' => [
                new Required('Enter your current password.'),
                new MaxLength(self::MAX_PASSWORD),
            ],
            'password' => [
                new Required('Choose a new password.'),
                new MinLength(8),
                new MaxLength(self::MAX_PASSWORD),
                new Confirmed(message: 'The passwords do not match.'),
            ],
        ]);

        if (!$result->passes()) {
            return $this->account($request, $result->errors(), status: Status::UnprocessableEntity);
        }

        if (!$this->isCurrentPassword($user, $data['current_password'])) {
            return $this->account(
                $request,
                ['current_password' => 'That is not your current password.'],
                status: Status::UnprocessableEntity,
            );
        }

        $this->users->updatePassword($user->id, $this->hasher->hash($data['password']));
        $this->guard->refresh($this->users->byIdentifier($user->id) ?? throw new NotFoundException);
        $this->auditPasswordChange($user);

        return $this->account($request, notice: Notice::saved());
    }

    /**
     * Asks, and does not apply: the address moves only when the link sent to
     * it is opened, so a typo or an address that is not theirs changes nothing.
     */
    private function changeEmail(Request $request, User $user, ParsedBody $input): Response
    {
        $data = [
            'email' => trim($input->string('email')),
            'current_password' => $input->string('current_password'),
        ];

        $result = $this->validator->validate($data, [
            'email' => [
                new Required('Enter the new address.'),
                new Email,
                new MaxLength(self::MAX_EMAIL),
            ],
            'current_password' => [
                new Required('Enter your current password.'),
                new MaxLength(self::MAX_PASSWORD),
            ],
        ]);

        if (!$result->passes()) {
            return $this->account($request, $result->errors(), self::EMAIL_FORM, status: Status::UnprocessableEntity);
        }

        $errors = match (true) {
            strcasecmp($data['email'], $user->email) === 0 => ['email' => 'That is already your address.'],
            $this->users->byEmail($data['email']) !== null => ['email' => 'That email address is already in use.'],
            default => [],
        };

        if ($errors === [] && !$this->isCurrentPassword($user, $data['current_password'])) {
            $errors = ['current_password' => 'That is not your current password.'];
        }

        if ($errors !== []) {
            return $this->account($request, $errors, self::EMAIL_FORM, status: Status::UnprocessableEntity);
        }

        $this->queue->push(SendEmailChangeLink::class, ['user' => $user->id, 'email' => $data['email']]);

        return $this->account($request, notice: Notice::success(
            "A link is on its way to {$data['email']}. Your address changes when you open it.",
        ));
    }

    /**
     * Both forms spend one budget, since both guess the same secret. Counted
     * before the check, not after a miss: once the budget is spent, a right
     * guess has to be refused the same as a wrong one.
     */
    private function isCurrentPassword(User $user, string $password): bool
    {
        $policy = new RateLimitPolicy('account-password', self::PASSWORD_CHECKS, self::PASSWORD_WINDOW);
        $status = $this->limiter->hit((string) $user->id, $policy);

        if (!$status->allowed) {
            throw new TooManyRequestsException($status->retryAfter);
        }

        return $this->hasher->verify($password, $user->passwordHash);
    }

    /**
     * Filed under the users module and the row's id, beside the rows an admin's
     * edits leave, so one account's history reads the same whoever changed it.
     * Swallowed like the listener's write: the password has already changed.
     */
    private function auditPasswordChange(User $user): void
    {
        try {
            $this->audit->record(new Audit(
                'users',
                (string) $user->id,
                null,
                null,
                $user->id,
                $user->username,
                'account.password_changed',
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Could not record audit: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    public function saveAppearance(Request $request): Response
    {
        $user = $this->guard->user() ?? throw new NotFoundException;
        $chosen = ParsedBody::fromRequest($request)->string(Themes::PREFERENCE);

        // Validated against the directory rather than a list: a theme that is
        // not on disk cannot be applied, so accepting its name would leave the
        // page unstyled and the setting stuck.
        if (!$this->themes->has($chosen)) {
            return $this->screen(
                $request,
                Notice::failure('That theme is not available.'),
                Status::UnprocessableEntity,
            );
        }

        $this->preferences->set($user->getAuthIdentifier(), Themes::PREFERENCE, $chosen);

        return $this->screen($request, Notice::saved());
    }

    public function saveRegional(Request $request): Response
    {
        $user = $this->guard->user() ?? throw new NotFoundException;
        $chosen = ParsedBody::fromRequest($request)->string(Timezones::PREFERENCE);

        // Validated against PHP's own list for the same reason the theme is
        // validated against the directory: an identifier nothing recognises
        // throws where it is used, which is halfway down every screen that
        // renders a date.
        if (!$this->timezones->has($chosen)) {
            return $this->regional(
                $request,
                Notice::failure('That is not a timezone this installation knows.'),
                Status::UnprocessableEntity,
            );
        }

        $this->preferences->set($user->getAuthIdentifier(), Timezones::PREFERENCE, $chosen);

        return $this->regional($request, Notice::saved());
    }

    private function screen(Request $request, Notice $notice, int|Status $status = Status::Ok): Response
    {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Appearance', notice: $notice),
            'admin/settings/appearance',
            $this->presenter->present(),
            status: $status,
        );
    }

    /** @param array<string, string> $errors */
    private function account(
        Request $request,
        array $errors = [],
        string $form = 'password',
        ?Notice $notice = null,
        int|Status $status = Status::Ok,
    ): Response {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Account', notice: $notice),
            'admin/settings/account',
            $this->accountPresenter->present($errors, $form),
            status: $status,
        );
    }

    private function regional(Request $request, Notice $notice, int|Status $status = Status::Ok): Response
    {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Regional', notice: $notice),
            'admin/settings/regional',
            $this->regionalPresenter->present(),
            status: $status,
        );
    }
}
