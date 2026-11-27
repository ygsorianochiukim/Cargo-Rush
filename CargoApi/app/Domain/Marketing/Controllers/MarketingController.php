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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
        $content = $request->validate(MarketingGenerator::contentRules());
        $request->validate([
            'image' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image');
        $remove = $request->boolean('remove_image');
        if ($file === null && ! $remove) {
            $draft->update(['content' => $content]);

            return $this->payload($draft->refresh()->toArray());
        }

        $path = $file?->store('marketing/'.$draft->company_id, 'local');
        abort_if($path === false, 503, 'The image could not be stored. Please try again.');
        $disk = Storage::disk('local');

        try {
            [$draft, $previous] = DB::transaction(function () use ($draft, $content, $path): array {
                $current = MarketingDraft::query()->lockForUpdate()->findOrFail($draft->id);
                $previous = $current->image_path;
                $current->content = $content;
                $current->image_path = $path;
                $current->save();

                return [$current, $previous];
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                $disk->delete($path);
            }

            throw $error;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $this->payload($draft->toArray());
    }

    public function draftImage(MarketingDraft $draft): StreamedResponse
    {
        $path = $draft->image_path;
        $disk = Storage::disk('local');
        abort_unless($path !== null && $disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
