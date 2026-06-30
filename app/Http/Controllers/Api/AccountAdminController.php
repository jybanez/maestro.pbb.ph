<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountAdminController extends Controller
{
    private const ROLES = ['admin', 'user'];
    private const STATUSES = ['active', 'disabled'];

    public function meta(): JsonResponse
    {
        return $this->ok([
            'app' => [
                'id' => 'pbb-maestro',
                'name' => 'PBB Maestro',
            ],
            'roles' => [
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'user', 'label' => 'User'],
            ],
            'statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'disabled', 'label' => 'Disabled'],
            ],
            'capabilities' => [
                'provisionUser' => true,
                'updateRole' => true,
                'blockLogin' => true,
                'suspendLogin' => false,
            ],
        ]);
    }

    public function show(string $pbbUserId): JsonResponse
    {
        $user = $this->findLinkedUser($pbbUserId);
        if (! $user) {
            return $this->fail('linked_user_not_found', 'Linked user not found.', 404);
        }

        return $this->ok([
            'user' => $this->userPayload($user),
        ]);
    }

    public function provision(Request $request, string $pbbUserId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'defaultRole' => ['nullable', 'string', Rule::in(self::ROLES)],
        ]);

        $role = in_array($data['defaultRole'] ?? '', self::ROLES, true)
            ? $data['defaultRole']
            : 'user';

        $linked = $this->findLinkedUser($pbbUserId);
        if ($linked) {
            $linked->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
            ])->save();

            return $this->ok([
                'user' => $this->userPayload($linked),
            ]);
        }

        $emailUser = User::query()->where('email', $data['email'])->first();
        if ($emailUser && $emailUser->pbb_user_id && $emailUser->pbb_user_id !== $pbbUserId) {
            return $this->fail('identity_conflict', 'A user with this email is linked to a different Account identity.', 409, [
                'email' => $data['email'],
            ]);
        }

        if ($emailUser) {
            $emailUser->forceFill([
                'pbb_user_id' => $pbbUserId,
                'name' => $data['name'],
                'email' => $data['email'],
                'status' => $emailUser->status ?: 'active',
            ])->save();

            return $this->ok([
                'user' => $this->userPayload($emailUser),
            ]);
        }

        $user = User::query()->create([
            'pbb_user_id' => $pbbUserId,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make(Str::random(48)),
        ]);

        return $this->ok([
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function updateRole(Request $request, string $pbbUserId): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(self::ROLES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->findLinkedUser($pbbUserId);
        if (! $user) {
            return $this->fail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $user->forceFill([
            'role' => $data['role'],
        ])->save();

        return $this->ok([
            'user' => $this->userPayload($user),
        ]);
    }

    public function updateStatus(Request $request, string $pbbUserId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'durationMinutes' => ['nullable', 'integer', 'min:1', 'max:5256000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->findLinkedUser($pbbUserId);
        if (! $user) {
            return $this->fail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $fill = [
            'status' => $data['status'],
        ];

        if ($data['status'] === 'disabled') {
            $fill['remember_token'] = Str::random(60);
        }

        $user->forceFill($fill)->save();

        return $this->ok([
            'user' => $this->userPayload($user),
        ]);
    }

    private function findLinkedUser(string $pbbUserId): ?User
    {
        return User::query()->where('pbb_user_id', $pbbUserId)->first();
    }

    private function userPayload(User $user): array
    {
        return [
            'pbbUserId' => $user->pbb_user_id,
            'localUserId' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?: 'user',
            'status' => $user->status ?: 'active',
            'blockedAt' => $user->status === 'disabled' ? optional($user->updated_at)->toIso8601String() : null,
            'suspendedUntil' => null,
            'updatedAt' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    private function fail(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
