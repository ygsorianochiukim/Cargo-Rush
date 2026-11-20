<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Payroll\Models\PayrollDiscordAlert;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PayrollDiscordAlertController extends ApiController
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeBot($request);
        $alerts = PayrollDiscordAlert::query()
            ->whereNull('delivered_at')->whereHas('run')
            ->with('run.lines')->orderBy('id')->limit(10)->get();

        return $this->payload($alerts->map(function (PayrollDiscordAlert $alert): array {
            $run = $alert->run;
            $gross = $run->grossCents();

            return [
                'id' => $alert->id,
                'event' => $alert->event,
                'status' => $run->status,
                'scope' => $run->scope()->value,
                'reference' => $run->reference,
                'period' => $run->periodLabel(),
                'pay_date' => $run->pay_date->toDateString(),
                'paid_date' => $run->paid_at?->copy()->tz('Asia/Manila')->toDateString(),
                'gross_cents' => $gross,
                'total_cost_cents' => $gross + $run->employerContributionsCents()['total'],
                'net_cents' => $run->netCents(),
                'payments' => $run->lines->map(static fn ($line): array => [
                    'name' => $line->name,
                    'net_cents' => $line->net_cents,
                ])->values(),
            ];
        })->all())->header('Cache-Control', 'private, no-store');
    }

    public function delivered(Request $request, string $alert): JsonResponse
    {
        $this->authorizeBot($request);
        PayrollDiscordAlert::query()->whereKey($alert)->whereNull('delivered_at')->update(['delivered_at' => now()]);

        return $this->noContent();
    }

    private function authorizeBot(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $this->assistant->isBotToken($token)
            && $request->user()->hasPermission('payroll.view'), 403);

        $settings = $this->assistant->settings();
        abort_unless($settings->enabled && $settings->discord_hr_channel_id !== null
            && $settings->discord_hr_channel_id === $request->input('channel_id'), 403);
    }
}
