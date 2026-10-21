<?php

declare(strict_types=1);

namespace App\Domain\Asset\Controllers;

use App\Domain\Asset\Models\AssetCategory;
use App\Domain\Asset\Requests\AssetCategoryRequest;
use App\Domain\Asset\Resources\AssetCategoryResource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $categories = AssetCategory::query()
            ->withCount('assets')
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->orderBy('name')
            ->get();

        return $this->collection(AssetCategoryResource::collection($categories), $categories);
    }

    public function store(AssetCategoryRequest $request): JsonResponse
    {
        $category = AssetCategory::create($request->toAttributes())->loadCount('assets');

        return $this->item(new AssetCategoryResource($category), status: 201);
    }

    public function update(AssetCategoryRequest $request, AssetCategory $assetCategory): JsonResponse
    {
        $assetCategory->update($request->toAttributes());

        return $this->item(new AssetCategoryResource($assetCategory->refresh()->loadCount('assets')));
    }

    public function destroy(AssetCategory $assetCategory): JsonResponse
    {
        if ($assetCategory->assets()->exists()) {
            $assetCategory->update(['status' => StatusValue::Inactive->value]);

            return $this->item(
                new AssetCategoryResource($assetCategory->refresh()->loadCount('assets')),
                ['retired' => true, 'reason' => 'Assets are filed under this category, so it was switched off rather than deleted.'],
            );
        }

        $assetCategory->delete();

        return $this->noContent();
    }
}
