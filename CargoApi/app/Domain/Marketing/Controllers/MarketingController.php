<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Controllers;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Marketing\Models\MarketingDraft;
use App\Domain\Marketing\Models\MarketingProfile;
use App\Domain\Marketing\Services\MarketingGenerator;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingController extends ApiController
{
    public function __construct(private readonly MarketingGenerator $generator) {}

    public function index(): JsonResponse
    {
        return $this->payload([
            'brand' => MarketingProfile::query()->first()?->brand,
            'ai_configured' => $this->generator->configured(AssistantSetting::query()->first()),
            'drafts' => MarketingDraft::query()->latest('updated_at')->latest('id')->limit(25)->get()->all(),
        ]);
    }

    public function updateBrand(Request $request): JsonResponse
    {
        $brand = $request->validate([
            'mission' => ['required', 'string', 'max:1000'],
            'vision' => ['required', 'string', 'max:1000'],
            'voice' => ['required', 'string', 'max:500'],
            'audience' => ['required', 'string', 'max:500'],
            'guidelines' => ['nullable', 'string', 'max:1500'],
        ]);

        $profile = MarketingProfile::query()->updateOrCreate([], ['brand' => $brand]);

        return $this->payload($profile->brand);
    }

    public function generate(Request $request, Tenant $tenant): JsonResponse
    {
        $input = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'use_trends' => ['sometimes', 'boolean'],
        ]);
        $brand = MarketingProfile::query()->first()?->brand;
        if ($brand === null) {
            throw ValidationException::withMessages(['brand' => 'Save your brand profile before generating a post.']);
        }

        $drafts = $this->generator->generate(
            AssistantSetting::query()->first(),
            $tenant->company()->name,
            $brand,
            $input['topic'],
            $input['use_trends'] ?? true,
        );

        $saved = DB::transaction(fn () => array_map(
            fn (array $draft) => MarketingDraft::query()->create(['topic' => $input['topic'], ...$draft]),
            $drafts,
        ));

        return $this->payload($saved)->setStatusCode(201);
    }

    public function updateDraft(Request $request, MarketingDraft $draft): JsonResponse
    {
        $draft->update(['content' => $request->validate(MarketingGenerator::contentRules())]);

        return $this->payload($draft->toArray());
    }
}
