<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // ── POST /api/v1/auth/register ─────────────────────────────────────────
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'   => ['required', 'string', 'max:80'],
            'last_name'    => ['required', 'string', 'max:80'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email'        => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone'        => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            'language_id'  => ['nullable', 'integer', 'exists:languages,id'],
            'country'      => ['nullable', 'string', 'max:60'],
            'timezone'     => ['nullable', 'string', 'max:60'],
        ]);

        $channel = DB::table('channels')->where('slug', 'cni-news')->first();

        $user = DB::transaction(function () use ($validated, $channel) {
            $user = User::create([
                'channel_id'            => $channel->id,
                'email'                 => $validated['email'],
                'phone'                 => $validated['phone'] ?? null,
                'password_hash'         => Hash::make($validated['password']),
                'first_name'            => $validated['first_name'],
                'last_name'             => $validated['last_name'],
                'display_name'          => $validated['display_name']
                                           ?? $validated['first_name'] . ' ' . $validated['last_name'],
                'preferred_language_id' => $validated['language_id'] ?? null,
                'country'               => $validated['country'] ?? 'GB',
                'timezone'              => $validated['timezone'] ?? 'Europe/London',
                'status'                => 'active',
            ]);

            // Assign member role
            $memberRole = DB::table('roles')->where('slug', 'member')->value('id');
            if ($memberRole) {
                DB::table('user_role_map')->insert([
                    'user_id'    => $user->id,
                    'role_id'    => $memberRole,
                    'channel_id' => $channel->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Assign free membership plan automatically
            $freePlan = MembershipPlan::where('is_free_tier', true)
                ->where('channel_id', $channel->id)
                ->first();

            if ($freePlan) {
                Membership::create([
                    'user_id'            => $user->id,
                    'membership_plan_id' => $freePlan->id,
                    'status'             => 'active',
                    'start_date'         => now()->toDateString(),
                    'auto_renew'         => false,
                ]);
            }

            return $user;
        });

        $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

        AuditLog::log('user_registered', 'user', $user->id, null, [
            'email' => $user->email,
        ]);

        return response()->json([
            'data'    => $this->userPayload($user),
            'token'   => $token,
            'message' => 'Registration successful.',
        ], 201);
    }

    // ── POST /api/v1/auth/login ────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'remember_me' => ['nullable', 'boolean'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            DB::table('login_history')->insert([
                'user_id'        => $user?->id,
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'success'        => false,
                'failure_reason' => 'invalid_credentials',
                'login_at'       => now(),
            ]);

            return response()->json([
                'errors' => ['email' => ['These credentials do not match our records.']],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'errors' => ['account' => ['Your account has been suspended. Please contact support.']],
            ], 403);
        }

        DB::table('login_history')->insert([
            'user_id'    => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'success'    => true,
            'login_at'   => now(),
        ]);

        $user->update(['last_login_at' => now()]);

        if (! $request->boolean('remember_me')) {
            $user->tokens()->delete();
        }

        $expiry = $request->boolean('remember_me') ? now()->addDays(90) : now()->addDays(1);
        $token  = $user->createToken('auth_token', ['*'], $expiry)->plainTextToken;

        return response()->json([
            'data'  => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    // ── POST /api/v1/auth/logout ───────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ── POST /api/v1/auth/refresh ──────────────────────────────────────────
    public function refresh(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $token = $request->user()->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
        return response()->json(['token' => $token]);
    }

    // ── GET /api/v1/auth/social/{provider}/redirect ────────────────────────
    public function socialRedirect(string $provider): JsonResponse
    {
        $this->validateProvider($provider);
        $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
        return response()->json(['redirect_url' => $url]);
    }

    // ── GET /api/v1/auth/social/{provider}/callback ────────────────────────
    public function socialCallback(string $provider): JsonResponse
    {
        $this->validateProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception) {
            return response()->json([
                'errors' => ['auth' => ['Social login failed. Please try again.']],
            ], 422);
        }

        $channel = DB::table('channels')->where('slug', 'cni-news')->first();

        $user = User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $nameParts = explode(' ', $socialUser->getName() ?? '', 2);
            $user = User::create([
                'channel_id'          => $channel->id,
                'email'               => $socialUser->getEmail(),
                'first_name'          => $nameParts[0] ?? 'User',
                'last_name'           => $nameParts[1] ?? '',
                'display_name'        => $socialUser->getName() ?? $socialUser->getEmail(),
                'is_email_verified'   => true,
                'status'              => 'active',
                'timezone'            => 'Europe/London',
            ]);

            $memberRole = DB::table('roles')->where('slug', 'member')->value('id');
            if ($memberRole) {
                DB::table('user_role_map')->insert([
                    'user_id'    => $user->id,
                    'role_id'    => $memberRole,
                    'channel_id' => $channel->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $token = $user->createToken('sso_' . $provider, ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'data'  => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    // ── GET /api/v1/me ─────────────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'memberships.plan']);
        return response()->json(['data' => $this->userPayload($user, detailed: true)]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function userPayload(User $user, bool $detailed = false): array
    {
        $base = [
            'id'           => $user->id,
            'display_name' => $user->display_name,
            'email'        => $user->email,
            'avatar_url'   => $user->avatar?->original_url,
            'status'       => $user->status,
        ];

        if ($detailed) {
            $membership = $user->memberships->where('status', 'active')->first();
            $base['first_name'] = $user->first_name;
            $base['last_name']  = $user->last_name;
            $base['timezone']   = $user->timezone;
            $base['roles']      = $user->roles->pluck('slug');
            $base['permissions'] = $user->roles
                ->flatMap(fn($r) => $r->permissions->pluck('key'))
                ->unique()->values();
            $base['membership'] = $membership ? [
                'plan'        => $membership->plan->name,
                'plan_slug'   => $membership->plan->slug,
                'badge'       => $membership->plan->badge_label,
                'badge_color' => $membership->plan->badge_color,
                'status'      => $membership->status,
                'ends_at'     => $membership->end_date,
                'features'    => $membership->plan->features ?? [],
            ] : null;
        }

        return $base;
    }

    private function validateProvider(string $provider): void
    {
        abort_unless(in_array($provider, ['google', 'facebook']), 422, 'Unsupported provider.');
    }
}
