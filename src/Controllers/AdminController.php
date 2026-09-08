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
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Pattern;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin controller
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

    #[Route('/')]
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

    #[Route('/users/new')]
    public function create(): Response
    {
        return $this->render('admin/user_form', ['vm' => new UserFormViewModel]);
    }

    #[Route('/users', methods: ['POST'])]
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

    #[Route('/users/{id}/edit')]
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

    #[Route('/users/{id}', methods: ['POST'])]
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

    #[Route('/users/{id}/delete', methods: ['POST'])]
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
     * user keeping their own name on an edit).
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

    /** Load a user by id or stop with a 404 */
    private function find(int $id): User
    {
        $user = $this->users->byIdentifier($id);
        if (!$user instanceof User) {
            $this->abort(Status::NotFound);
        }

        return $user;
    }
}
