<?php

declare(strict_types=1);

namespace App\Domain\Asset\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTurnover;
use App\Domain\Asset\Repositories\AssetRepository;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetService extends CrudService
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly AssetTagger $tagger,
    ) {}

    protected function repository(): Repository
    {
        return $this->assets;
    }

    public function create(Data $data): Model
    {
        return DB::transaction(function () use ($data): Asset {
            $attributes = $data->persistable();
            $custodian = $attributes['custodian_id'] ?? null;

            if (($attributes['tag'] ?? null) === null) {
                $attributes['tag'] = $this->tagger->next($attributes['purchased_on'] ?? null);
            }

            if ($custodian === null) {
                unset($attributes['custodian_id'], $attributes['assigned_on']);
            } else {
                $attributes['status'] = StatusValue::Assigned->value;
                $attributes['assigned_on'] = $attributes['assigned_on']
                    ?? $attributes['purchased_on']
                    ?? now()->toDateString();
            }

            /** @var Asset $asset */
            $asset = Asset::create($attributes);

            if ($custodian !== null) {
                $asset->turnovers()->create([
                    'from_employee_id' => null,
                    'to_employee_id' => $custodian,
                    'turned_over_on' => $asset->assigned_on,
                    'condition' => $asset->condition ?? 'good',
                    'status' => StatusValue::Assigned->value,
                    'note' => 'Issued on registration.',
                    'recorded_by' => auth()->id(),
                ]);
            }

            return $this->assets->findOrFail($asset->id);
        });
    }

    public function update(Model $model, Data $data): Model
    {
        /** @var Asset $model */
        $attributes = $data->persistable();
        unset($attributes['custodian_id'], $attributes['assigned_on']);

        if (array_key_exists('tag', $attributes) && $attributes['tag'] === null) {
            unset($attributes['tag']);
        }

        if (isset($attributes['status']) && $model->custodian_id !== null
            && $attributes['status'] !== $model->status->value) {
            throw ValidationException::withMessages([
                'status' => 'Somebody holds this asset. Record a turnover to take it back first.',
            ]);
        }

        $model->update($attributes);

        return $this->assets->findOrFail($model->id);
    }

    /** @param array<string, mixed> $input */
    public function turnover(Asset $asset, array $input): Asset
    {
        $to = $input['to_employee_id'] ?? null;

        if ($to === $asset->custodian_id) {
            throw ValidationException::withMessages([
                'to_employee_id' => $to === null
                    ? 'Nobody holds this asset, so there is nothing to take back.'
                    : 'That person already holds this asset.',
            ]);
        }

        if ($asset->assigned_on !== null && $input['turned_over_on'] < $asset->assigned_on->toDateString()) {
            throw ValidationException::withMessages([
                'turned_over_on' => 'A turnover cannot be dated before the asset was last handed over.',
            ]);
        }

        $status = $to === null
            ? ($input['status'] ?? StatusValue::Available->value)
            : StatusValue::Assigned->value;

        $condition = $input['condition'] ?? $asset->condition;

        return DB::transaction(function () use ($asset, $input, $to, $status, $condition): Asset {
            AssetTurnover::create([
                'asset_id' => $asset->id,
                'from_employee_id' => $asset->custodian_id,
                'to_employee_id' => $to,
                'turned_over_on' => $input['turned_over_on'],
                'condition' => $condition,
                'status' => $status,
                'note' => $input['note'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            $asset->update([
                'custodian_id' => $to,
                'assigned_on' => $to === null ? null : $input['turned_over_on'],
                'condition' => $condition,
                'status' => $status,
            ]);

            return $this->assets->findOrFail($asset->id);
        });
    }
}
