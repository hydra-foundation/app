<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Authorization\ManageUser;
use App\Authorization\RequireAdmin;
use App\Entities\User;
use Hydra\Http\Input;
use App\Repositories\UserRepository;
use Hydra\View\Contracts\ViewInterface;
use App\ViewModels\AdminViewModel;
use App\ViewModels\UserFormViewModel;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Attributes\RouteGroup;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Pattern;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The authorization vertical slice: an admin-only area reached through two
 * gates, one per question — both answered in middleware, before any controller
 * body runs. Now that the area has more than one page, those two gates live on
 * the class as a #[RouteGroup] instead of being repeated on every route:
 *
 *   - AuthenticateMiddleware answers "who are you?" — an anonymous visitor never
 *     reaches a controller (401 → redirect to /login, the auth slice's policy).
 *   - RequireAdmin then answers "are you allowed?" — a logged-in non-admin is
 *     stopped with a 403 (the package's AuthorizationException), a dead end, not
 *     a redirect. Order matters: authenticate OUTSIDE authorize, so the anonymous
 *     case is a 401 redirect, not a 403 from an ability that denies a null user.
 *
 * The group also supplies the shared /admin prefix, so each method's #[Route]
 * carries only its own tail. Grouping is a scan-time fold (see RouteGroup): the
 * Router still sees a flat list of fully-qualified routes.
 *
 * The two group gates answer the role question once for the whole area. What
 * stays in the controller body is the one question middleware can't answer: the
 * SUBJECT-bound rule — "may this admin manage THIS user?" — checked with
 * `gate->authorize(ManageUser::class, $target)` on edit/update/delete, because
 * the verdict depends on the record being acted on (see {@see ManageUser}). That
 * is the deliberate division: flat role checks ride the route as middleware;
 * record-specific checks stay here, next to the record they load.
 */
#[RouteGroup('/admin', middleware: [AuthenticateMiddleware::class, RequireAdmin::class])]
final class AdminController extends Controller
{
    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly HasherInterface $hasher,
        private readonly SessionInterface $session,
        private readonly GateInterface $gate,
        private readonly GuardInterface $guard,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/')] // → /admin (root collapses to the bare prefix)
    public function index(): Response
    {
        // One-shot flash from a preceding create/update/delete (post-redirect-get).
        $status = $this->session->getFlash('status');
        // The route is admin-gated, so user() is the signed-in admin (our User).
        $current = $this->guard->user();

        return $this->render('admin/index', ['vm' => new AdminViewModel(
            $this->users->all(),
            is_string($status) ? $status : null,
            $current instanceof User ? $current->id : null,
        )]);
    }

    #[Route('/users/new')] // → /admin/users/new
    public function create(): Response
    {
        return $this->render('admin/user_form', ['vm' => new UserFormViewModel]);
    }

    #[Route('/users', methods: ['POST'])] // → POST /admin/users
    public function store(Request $request): Response
    {
        $input = Input::fromRequest($request);
        $username = trim($input->string('username'));
        $password = $input->string('password'); // not trimmed — spaces may be intended
        $role = $input->string('role', 'user');

        // Identity rules are shared with update(); the password rules are
        // create-only (an edit never touches the digest).
        $result = $this->validator->validate(
            ['username' => $username, 'password' => $password, 'role' => $role],
            $this->identityRules() + [
                'password' => [
                    new Required('Enter a password.'),
                    new MinLength(8, 'Password must be at least 8 characters.'),
                ],
            ],
        );
        $errors = $this->withUniquenessError($result->errors(), $username);

        if ($errors !== []) {
            return $this->render(
                'admin/user_form',
                ['vm' => new UserFormViewModel(username: $username, role: $role, errors: $errors)],
                Status::UnprocessableEntity,
            );
        }

        // create() stores the digest we hand it; hashing stays in the hasher.
        $this->users->create($username, $this->hasher->hash($password), $role);
        $this->session->flash('status', "User \u{201C}{$username}\u{201D} created.");

        return $this->respond->redirect('/admin');
    }

    #[Route('/users/{id}/edit')] // → /admin/users/{id}/edit
    public function edit(int $id): Response
    {
        $user = $this->find($id);
        // Subject-bound: an admin can't open their own row here (see ManageUser).
        $this->gate->authorize(ManageUser::class, $user);

        return $this->render('admin/user_form', ['vm' => new UserFormViewModel(
            username: $user->username,
            role: $user->role,
            id: $user->id,
        )]);
    }

    #[Route('/users/{id}', methods: ['POST'])] // → POST /admin/users/{id}
    public function update(int $id, Request $request): Response
    {
        $user = $this->find($id);
        $this->gate->authorize(ManageUser::class, $user);

        $input = Input::fromRequest($request);
        $username = trim($input->string('username'));
        $role = $input->string('role', $user->role);

        // No password rules: an edit changes username and role only.
        $result = $this->validator->validate(
            ['username' => $username, 'role' => $role],
            $this->identityRules(),
        );
        // Uniqueness must ignore THIS user's own current name (unchanged is fine).
        $errors = $this->withUniquenessError($result->errors(), $username, exceptId: $id);

        if ($errors !== []) {
            return $this->render(
                'admin/user_form',
                ['vm' => new UserFormViewModel(username: $username, role: $role, errors: $errors, id: $id)],
                Status::UnprocessableEntity,
            );
        }

        $this->users->update($id, $username, $role);
        $this->session->flash('status', "User \u{201C}{$username}\u{201D} updated.");

        return $this->respond->redirect('/admin');
    }

    #[Route('/users/{id}/delete', methods: ['POST'])] // → POST /admin/users/{id}/delete
    public function destroy(int $id): Response
    {
        $user = $this->find($id);
        // Self-protection: an admin can't delete themselves out of the system.
        $this->gate->authorize(ManageUser::class, $user);

        $this->users->delete($id);
        $this->session->flash('status', "User \u{201C}{$user->username}\u{201D} deleted.");

        return $this->respond->redirect('/admin');
    }

    /**
     * The shared username + role rules. Identity validation that create() and
     * update() both run; create() adds password rules on top with `+`.
     *
     * @return array<string, list<RuleInterface>>
     */
    private function identityRules(): array
    {
        return [
            'username' => [
                new Required('Enter a username.'),
                new MinLength(3, 'Username must be at least 3 characters.'),
                new MaxLength(64, 'Username must be at most 64 characters.'),
                new Pattern('/^[A-Za-z0-9_]+$/', 'Letters, numbers and underscores only.'),
            ],
            'role' => [new Pattern('/^(user|admin)$/', 'Role must be user or admin.')],
        ];
    }

    /**
     * Add a "username taken" error unless the name is structurally invalid
     * already (no point querying for it) or it belongs to {@see $exceptId} (a
     * user keeping their own name on an edit). Uniqueness is app data, checked
     * here against the repository, not a generic validation rule.
     *
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function withUniquenessError(array $errors, string $username, ?int $exceptId = null): array
    {
        if (isset($errors['username'])) {
            return $errors;
        }

        $existing = $this->users->byUsername($username);
        if ($existing !== null && $existing->getAuthIdentifier() !== $exceptId) {
            $errors['username'] = 'That username is already taken.';
        }

        return $errors;
    }

    /** Load a user by id or stop with a 404 — the load-or-abort the write routes share. */
    private function find(int $id): User
    {
        $user = $this->users->byIdentifier($id);
        if (!$user instanceof User) {
            $this->abort(Status::NotFound);
        }

        return $user;
    }
}
