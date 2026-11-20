<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDiscordAlert extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['company_id', 'pay_run_id', 'event', 'delivered_at'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id');
    }

    public static function enqueue(PayRun $run, string $event): void
    {
        if (! AssistantSetting::query()->where('company_id', $run->company_id)
            ->where('enabled', true)->whereNotNull('discord_hr_channel_id')->exists()) {
            return;
        }

        self::query()->firstOrCreate([
            'company_id' => $run->company_id, 'pay_run_id' => $run->id, 'event' => $event,
        ]);
    }
}
