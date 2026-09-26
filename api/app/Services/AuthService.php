<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    /**
     * Refresh-token lifetime in days (PRD §8.4: 30 days).
     */
    public const REFRESH_TTL_DAYS = 30;

    /**
     * Issue a fresh access token (RS256) + a new rotating refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokens(User $user, Request $request): array
    {
        $accessToken = $this->mint($user);

        $plainRefresh = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plainRefresh),
            'device_info' => Str::limit((string) $request->userAgent(), 1000, ''),
            'ip_address' => $request->ip(),
            'expires_at' => Carbon::now()->addDays(self::REFRESH_TTL_DAYS),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $plainRefresh,
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }

    /**
     * Issue an access token that acts as $target, marked as an impersonation
     * session opened by $admin (claim `act_as`).
     *
     * Deliberately mints ONLY an access token: no UserRefreshToken row and no
     * refresh cookie. The admin's own refresh cookie is left untouched, which is
     * what makes "kembali ke admin" possible without re-login — the frontend just
     * drops this token and refreshes from the admin's still-valid cookie. It also
     * means the impersonation dies on its own (tab close, token expiry) instead
     * of becoming a 30-day session for someone else's account.
     */
    public function issueImpersonationToken(User $target, User $admin): string
    {
        return $this->mint($target, ['act_as' => $admin->id]);
    }

    /**
     * Cetak satu access token, dan **buang dulu klaim sisa milik orang lain**.
     *
     * Semua cetakan lewat sini karena resetnya tidak boleh dilewatkan satu pun,
     * dan itu bukan kehati-hatian berlebih — tanpanya `act_as` bocor ke token
     * yang sah:
     *
     *  - `Tymon\JWTAuth\Factory` adalah singleton, dan `make()` **tidak**
     *    mengosongkan Collection `$claims`-nya (`$resetClaims` default false,
     *    `addClaim()` cuma `put()` di atasnya). Jadi `act_as` dari satu cetakan
     *    tetap di sana dan **tertandatangani ke dalam** token biasa berikutnya.
     *  - Factory yang sama juga diisi saat **membaca**: `Manager::decode()`
     *    memanggil `customClaims($payloadArray)->make()`. Satu request yang
     *    memakai token impersonasi sudah cukup untuk mengotorinya, jadi reset di
     *    sisi cetak saja — di dalam `issueImpersonationToken()` — masih
     *    menyisakan lubangnya.
     *  - `customClaims([])` mengurus store kedua: array di instance
     *    `tymon.jwt.auth` sendiri, yang `getClaimsArray()` merge di tiap cetakan.
     *
     * Semuanya berujung di satu akibat, dan diam: EnsurePasswordRotated melihat
     * `act_as` di token yang sah, lalu mempersilakan password undangan yang sudah
     * dibaca orang lain masuk ke permukaan tugas — tepat yang ia ada untuk
     * menahan. Di request-per-proses PHP-FPM pola itu jarang terlihat; di Octane
     * dan queue worker prosesnya hidup terus. Middleware itu menjaga sisi
     * bacanya (ia men-decode token request itu sendiri, bukan `payload()`);
     * **keduanya** perlu, karena reset di sini saja tetap menyisakan token sah
     * yang berbohong soal dirinya, dan pembaca ketiga nanti akan memercayainya.
     */
    private function mint(User $user, array $claims = []): string
    {
        try {
            JWTAuth::factory()->emptyClaims();

            return JWTAuth::customClaims($claims)->fromUser($user);
        } finally {
            // Dibersihkan di kedua sisi, dan yang sesudah bukan mubazir: yang
            // sebelum menjaga token *ini* dari klaim orang lain, yang sesudah
            // menjaga pencetak lain dari klaim *ini*. `JWTAuth::fromUser()`
            // bukan satu-satunya pintu cetak — `auth('api')->login()` memakai
            // instance `tymon.jwt` yang berbeda tapi Factory yang sama, jadi ia
            // tidak akan pernah lewat sini untuk direset. Selama semua cetakan
            // meninggalkan Factory-nya kosong, siapa pun pencetak berikutnya
            // aman tanpa perlu tahu soal ini.
            JWTAuth::factory()->emptyClaims();
            JWTAuth::customClaims([]);
        }
    }

    /**
     * Validate a refresh token, rotate it (single-use), and issue a new pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function rotate(string $plainRefresh, Request $request): ?array
    {
        $record = UserRefreshToken::where('token_hash', $this->hash($plainRefresh))->first();

        if (! $record || ! $record->isActive()) {
            return null;
        }

        $record->forceFill(['revoked_at' => Carbon::now()])->save();

        $user = $record->user;
        if (! $user) {
            return null;
        }

        return $this->issueTokens($user, $request);
    }

    /**
     * Revoke a single refresh token (logout on this device).
     */
    public function revoke(string $plainRefresh): void
    {
        UserRefreshToken::where('token_hash', $this->hash($plainRefresh))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * Revoke every refresh token of $user, optionally sparing the one that made
     * the request — "sign out my other devices".
     *
     * This is what makes a password change mean something: the new password
     * alone would not stop a session someone else already holds, because a
     * refresh token is a bearer credential that never re-checks the password.
     *
     * Access tokens already handed out are NOT killed (JWT is stateless), so a
     * stolen session survives at most one `jwt.ttl` window — the refresh that
     * would have extended it into 30 more days is what dies here.
     *
     * @return int rows revoked
     */
    public function revokeAllFor(User $user, ?string $exceptPlain = null): int
    {
        return UserRefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptPlain, fn ($q, $plain) => $q->where('token_hash', '!=', $this->hash($plain)))
            ->update(['revoked_at' => Carbon::now()]);
    }

    protected function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
