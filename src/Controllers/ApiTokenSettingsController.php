<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Presenters\ApiTokensPresenter;
use App\Entities\Audit;
use App\Entities\User;
use App\Repositories\AuditRepository;
use App\Security\CurrentPassword;
use App\ViewModels\ApiTokensViewModel;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\ParsedBody;
use Hydra\Http\Status;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/** The API tokens settings screen: making a personal token, and revoking one. */
final class ApiTokenSettingsController
{
    private const MAX_NAME = 100;

    private const CREATES = 10;

    private const WINDOW = 3600;

    /** The same ceiling the login form holds a password to. */
    private const MAX_PASSWORD = 4096;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GuardInterface $guard,
        private readonly ApiTokensPresenter $presenter,
        private readonly ApiTokens $tokens,
        private readonly ApiTokenStoreInterface $store,
        private readonly RateLimiter $limiter,
        private readonly ClockInterface $clock,
        private readonly AuditRepository $audit,
        private readonly LoggerInterface $logger,
        private readonly CurrentPassword $currentPassword,
    ) {}

    public function save(Request $request): Response
    {
        $user = $this->guard->user();

        if (!$user instanceof User) {
            throw new NotFoundException;
        }

        $input = ParsedBody::fromRequest($request);

        return match ($input->string('intent')) {
            'create' => $this->create($request, $user, $input),
            'revoke' => $this->revoke($request, $user, $input),
            default => $this->screen($request, notice: Notice::failure('That form is out of date. Here is where things stand now.')),
        };
    }

    private function create(Request $request, User $user, ParsedBody $input): Response
    {
        $old = ['label' => trim($input->string('label')), 'expires' => $input->string('expires')];
        $errors = [];

        if ($old['label'] === '' || mb_strlen($old['label']) > self::MAX_NAME) {
            $errors['label'] = sprintf('Give the token a name of up to %d characters.', self::MAX_NAME);
        }

        if (!array_key_exists($old['expires'], ApiTokensViewModel::EXPIRIES)) {
            $errors['expires'] = 'Choose when the token expires.';
        }

        // Asked like every other settings form that hands out a way in: a
        // token outlives the session that made it, so a borrowed session must
        // not be enough to mint one.
        $password = $input->string('current_password');

        if ($password === '' || strlen($password) > self::MAX_PASSWORD) {
            $errors['current_password'] = 'Enter your current password.';
        }

        if ($errors === [] && !$this->currentPassword->matches($user, $password)) {
            $errors['current_password'] = 'That is not your current password.';
        }

        if ($errors !== []) {
            return $this->screen($request, $errors, $old, status: Status::UnprocessableEntity);
        }

        $status = $this->limiter->hit((string) $user->id, new RateLimitPolicy('api-token-create', self::CREATES, self::WINDOW));

        if (!$status->allowed) {
            throw new TooManyRequestsException($status->retryAfter);
        }

        $expiresAt = $old['expires'] === 'never' ? null : $this->clock->now()->modify("+{$old['expires']} days");
        $issued = $this->tokens->issue($user, $old['label'], $expiresAt);

        $this->record($user, 'account.api_token_created');

        return $this->screen($request, plain: $issued->plain, plainName: $issued->token->name);
    }

    private function revoke(Request $request, User $user, ParsedBody $input): Response
    {
        $id = $input->string('token');

        if (!ctype_digit($id) || !$this->store->revoke($user, (int) $id)) {
            throw new NotFoundException;
        }

        $this->record($user, 'account.api_token_revoked');

        return $this->screen($request, notice: Notice::success('Token revoked. Anything using it is signed out.'));
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
     * @param array<string, string> $old
     */
    private function screen(
        Request $request,
        array $errors = [],
        array $old = [],
        ?string $plain = null,
        ?string $plainName = null,
        ?Notice $notice = null,
        int|Status $status = Status::Ok,
    ): Response {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'API tokens', notice: $notice),
            'admin/settings/tokens',
            $this->presenter->present($errors, $old, $plain, $plainName),
            status: $status,
        );
    }
}
