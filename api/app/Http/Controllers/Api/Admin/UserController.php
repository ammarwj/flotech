<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin management of platform users.
 *
 * The platform role is a plain `role` column (super_admin | user) — not a pivot.
 * Per-organization roles (admin | operator) live in organization_members and are
 * NOT touched here.
 */
class UserController extends Controller
{
    public function __construct(protected AuthService $auth) {}

    /** Paginated, searchable list with each user's org context. */
    public function index(Request $request): JsonResponse
    {
        $page = User::query()
            ->when($request->query('q'), function ($query, $q) {
                $query->where(fn ($w) => $w
                    ->where('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($request->query('role'), fn ($query, $role) => $query->where('role', $role))
            ->with(['ownedOrganizations', 'organizationMemberships.organization'])
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::success([
            'items' => UserResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // Guard the last door: a super_admin must not be able to demote their own
        // account and lock everyone out of the admin panel.
        if (array_key_exists('role', $data) && $user->id === auth('api')->id() && $data['role'] !== $user->role) {
            return ApiResponse::error('Tidak bisa mengubah role akun sendiri.', null, 422);
        }

        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
        }

        // email_verified_at tracks is_verified: verifying stamps a timestamp,
        // un-verifying clears it, so the two never disagree.
        if (array_key_exists('is_verified', $data)) {
            $user->is_verified = $data['is_verified'];
            $user->email_verified_at = $data['is_verified'] ? ($user->email_verified_at ?? now()) : null;
        }

        $user->save();

        $user->load(['ownedOrganizations', 'organizationMemberships.organization']);

        return ApiResponse::success(new UserResource($user), 'User diperbarui');
    }

    /**
     * "Login as" — hand the super admin an access token that acts as $user, so
     * support can reproduce a user's view without knowing their password.
     *
     * The response deliberately carries NO refresh cookie: the admin's own
     * refresh cookie stays untouched so they can drop this token and refresh
     * back into their own account. See AuthService::issueImpersonationToken().
     */
    public function impersonate(User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = auth('api')->user();

        if ($user->id === $admin->id) {
            return ApiResponse::error('Tidak bisa login sebagai akun sendiri.', null, 422);
        }

        // A super admin impersonating another super admin would let one admin act
        // with full platform powers under someone else's name — and nothing here
        // records it. Keep impersonation pointed at ordinary users only.
        if ($user->role === 'super_admin') {
            return ApiResponse::error('Tidak bisa login sebagai sesama super admin.', null, 403);
        }

        $token = $this->auth->issueImpersonationToken($user, $admin);

        $user->load(['ownedOrganizations', 'organizationMemberships.organization']);

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'user' => new UserResource($user),
        ], 'Masuk sebagai '.($user->full_name ?: $user->email));
    }

    /**
     * Set a user's password on their behalf — the support path for someone who
     * lost their email or never received the reset link.
     *
     * Same target restriction as impersonate(), for the same reason: handing one
     * super admin the keys to another's account is a silent takeover of the
     * whole platform, and nothing here records who did it. A super admin changes
     * their own password from /account like everyone else, which is also why
     * self is refused rather than special-cased — that path asks for the current
     * password, and this one deliberately doesn't.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        if ($user->id === auth('api')->id()) {
            return ApiResponse::error('Ubah password akun sendiri lewat halaman Akun Saya.', null, 422);
        }

        if ($user->role === 'super_admin') {
            return ApiResponse::error('Tidak bisa mereset password sesama super admin.', null, 403);
        }

        $user->forceFill([
            'password' => $request->string('password'),
            'remember_token' => Str::random(60),
        ])->save();

        // Every session of theirs dies: a reset that left the old sessions alive
        // would be useless for the case this exists for (a compromised account).
        $this->auth->revokeAllFor($user);

        return ApiResponse::success(null, 'Password user direset. Sampaikan password barunya lewat kanal yang aman.');
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth('api')->id()) {
            return ApiResponse::error('Tidak bisa menghapus akun sendiri.', null, 422);
        }

        // owner_id is nullOnDelete: deleting an owner would leave a live org
        // (events + wallet intact) with no owner. Force the admin to reassign or
        // delete those organizations first.
        if ($user->ownedOrganizations()->exists()) {
            return ApiResponse::error(
                'User masih memiliki organisasi. Alihkan atau hapus organisasinya dulu.',
                null,
                409,
            );
        }

        $user->delete();

        return ApiResponse::success(null, 'User dihapus');
    }
}
