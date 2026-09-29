<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Controllers;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Supplier\Models\SupplierCategory;
use App\Domain\Supplier\Requests\SupplierCategoryRequest;
use App\Domain\Supplier\Resources\SupplierCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kinds of supplier — kept on Access Control.
 *
 * A category somebody has filed suppliers under is retired rather than
 * deleted, so an old shop never loses its label; one nobody used goes.
 */
class SupplierCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $categories = SupplierCategory::query()
            ->withCount('suppliers')
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->orderBy('name')
            ->get();

        return $this->collection(SupplierCategoryResource::collection($categories), $categories);
    }

    public function store(SupplierCategoryRequest $request): JsonResponse
    {
        $supplierCategory = SupplierCategory::create($request->toAttributes())->loadCount('suppliers');

        return $this->item(new SupplierCategoryResource($supplierCategory), status: 201);
    }

    public function update(SupplierCategoryRequest $request, SupplierCategory $supplierCategory): JsonResponse
    {
        $supplierCategory->update($request->toAttributes());

        return $this->item(new SupplierCategoryResource($supplierCategory->refresh()->loadCount('suppliers')));
    }

    public function destroy(SupplierCategory $supplierCategory): JsonResponse
    {
        if ($supplierCategory->suppliers()->exists()) {
            $supplierCategory->update(['status' => StatusValue::Inactive->value]);

            return $this->item(
                new SupplierCategoryResource($supplierCategory->refresh()->loadCount('suppliers')),
                ['retired' => true, 'reason' => 'Suppliers are filed under this category, so it was switched off rather than deleted.'],
            );
        }

        $supplierCategory->delete();

        return $this->noContent();
    }
}
