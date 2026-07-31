<?php

namespace Goldnead\BrandContext\Http\Controllers\Cp;

use Goldnead\BrandContext\BrandManager;
use Goldnead\BrandContext\BrandMembership;
use Goldnead\BrandContext\Contracts\UserSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

/**
 * Manage the brand membership of Control Panel users.
 *
 * The screen always works on the **current** brand — the one in the brand
 * switcher. There is no brand picker in the form, and the brand is never read
 * from the request: an operator of brand A cannot write a membership for brand
 * B by editing the payload, because the payload has no say in it. That is the
 * whole isolation argument for this screen, and it is asserted by a test that
 * posts another brand's id and checks where the row landed.
 */
class BrandUserController extends BaseController
{
    public function __construct(
        protected BrandMembership $members,
        protected BrandManager $manager,
    ) {}

    public function index(Request $request, UserSource $users)
    {
        $this->authorizeOrFail($request);

        $brand = $this->manager->current();
        $assigned = $this->members->assignedUserIdsOf($brand)->all();

        $rows = $users->all()
            ->map(fn ($user) => [
                'id' => (string) $user->id(),
                'name' => $this->displayName($user),
                'email' => (string) $user->email(),
                'assigned' => in_array((string) $user->id(), $assigned, true),
                'unassigned_anywhere' => $this->members->isUnassigned($user),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Inertia::render('brand-context::Users', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name, 'handle' => $brand->handle],
            'users' => $rows,
            'attachUrl' => cp_route('brand-context.users.store'),
            'detachUrl' => cp_route('brand-context.users.destroy'),
            'canManage' => $this->userCan($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request);

        $userId = $this->validateUser($request);

        if ($userId instanceof RedirectResponse) {
            return $userId;
        }

        $this->members->attach($userId, $this->manager->current());

        return back();
    }

    public function destroy(Request $request)
    {
        $this->authorizeOrFail($request);

        $userId = $this->validateUser($request);

        if ($userId instanceof RedirectResponse) {
            return $userId;
        }

        $this->members->detach($userId, $this->manager->current());

        return back();
    }

    /**
     * A rejected assignment has to say why, at the field it came from.
     *
     * The failure this guards against is not a typo in a form — the id comes
     * from a list — but a user that was deleted between rendering the screen
     * and clicking the button. Without a message the row would simply refuse to
     * change state, which reads as a broken toggle.
     */
    protected function validateUser(Request $request): string|RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:191'],
        ]);

        $known = app(UserSource::class)->all()
            ->contains(fn ($user) => (string) $user->id() === $data['user_id']);

        if (! $known) {
            return back()->withErrors([
                'user_id' => __('brand-context::messages.unknown_user'),
            ]);
        }

        return $data['user_id'];
    }

    protected function displayName($user): string
    {
        $name = method_exists($user, 'name') ? $user->name() : null;

        return $name ?: (string) $user->email();
    }

    /**
     * Permission checks go through Laravel's Gate rather than Statamic's
     * `hasPermission()`: Statamic registers a `Gate::after` hook that resolves
     * the Statamic user and short-circuits super users, so `can()` is correct
     * for the file *and* the eloquent users repository. Calling
     * `hasPermission()` on the raw auth user crashes on eloquent-driver sites
     * where the authenticated model is a plain `App\Models\User`.
     */
    protected function authorizeOrFail(Request $request): void
    {
        if (! $this->userCan($request)) {
            abort(403);
        }
    }

    protected function userCan(Request $request): bool
    {
        return (bool) $request->user()?->can('manage brand members');
    }
}
