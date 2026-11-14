<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantPairing;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantPairingService
{
    public const LIFETIME_MINUTES = 10;

    private const MAX_WAITING = 5;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly Tenant $tenant,
    ) {}

    /** @return array{id: string, code: string, secret: string, expires_at: string} */
    public function request(?string $ip): array
    {
        AssistantPairing::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        if (AssistantPairing::query()->waiting()->count() >= self::MAX_WAITING) {
            throw new HttpException(429, 'Too many bots are waiting to connect. Approve or dismiss them in Assistant Settings first.');
        }

        $secret = Str::random(48);

        $pairing = AssistantPairing::query()->create([
            'code' => $this->code(),
            'secret_hash' => hash('sha256', $secret),
            'status' => AssistantPairing::PENDING,
            'ip_address' => $ip,
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return [
            'id' => $pairing->id,
            'code' => $pairing->code,
            'secret' => $secret,
            'expires_at' => $pairing->expires_at->toIso8601String(),
        ];
    }

    /** @return array{status: string, token?: string} */
    public function poll(AssistantPairing $pairing, string $secret): array
    {
        if (! hash_equals($pairing->secret_hash, hash('sha256', $secret))) {
            throw new HttpException(404, 'No such pairing.');
        }

        return DB::transaction(function () use ($pairing): array {
            $pairing = AssistantPairing::query()->lockForUpdate()->findOrFail($pairing->id);

            if ($pairing->status === AssistantPairing::APPROVED && $pairing->token !== null) {
                $token = $pairing->token;
                $pairing->update(['status' => AssistantPairing::CLAIMED, 'token' => null, 'claimed_at' => now()]);

                return ['status' => AssistantPairing::APPROVED, 'token' => $token];
            }

            if ($pairing->status === AssistantPairing::PENDING && $pairing->expired()) {
                return ['status' => 'expired'];
            }

            return ['status' => $pairing->status];
        });
    }

    /** @return array<int, array{id: string, code: string, ip_address: ?string, requested_at: ?string, expires_at: string}> */
    public function waiting(): array
    {
        return AssistantPairing::query()
            ->waiting()
            ->latest()
            ->get()
            ->map(static fn (AssistantPairing $p): array => [
                'id' => $p->id,
                'code' => $p->code,
                'ip_address' => $p->ip_address,
                'requested_at' => $p->created_at?->toIso8601String(),
                'expires_at' => $p->expires_at->toIso8601String(),
            ])
            ->all();
    }

    public function approve(AssistantPairing $pairing): void
    {
        if ($pairing->status !== AssistantPairing::PENDING || $pairing->expired()) {
            throw new HttpException(410, 'That request has expired. Restart the bot to get a new code.');
        }

        DB::transaction(function () use ($pairing): void {
            AssistantPairing::query()
                ->where('id', '!=', $pairing->id)
                ->where('status', AssistantPairing::APPROVED)
                ->update(['status' => AssistantPairing::DISMISSED, 'token' => null]);

            $pairing->update([
                'company_id' => $this->tenant->id(),
                'token' => $this->assistant->issueToken(),
                'status' => AssistantPairing::APPROVED,
                'approved_at' => now(),
            ]);
        });
    }

    public function dismiss(AssistantPairing $pairing): void
    {
        if ($pairing->status === AssistantPairing::PENDING) {
            $pairing->update(['status' => AssistantPairing::DISMISSED]);
        }
    }

    private function code(): string
    {
        $pick = static fn (): string => collect(range(1, 4))
            ->map(static fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
            ->implode('');

        return $pick().'-'.$pick();
    }
}
