<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\CompanyLogoRequest;
use App\Domain\Tenancy\Requests\CompanyProfileRequest;
use App\Domain\Tenancy\Resources\CompanyResource;
use App\Domain\Tenancy\Services\CompanyService;
use Illuminate\Http\JsonResponse;

class CompanyController extends ApiController
{
    public function __construct(private readonly CompanyService $company) {}

    public function show(): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->current()));
    }

    public function update(CompanyProfileRequest $request): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->update($request->toAttributes())));
    }

    public function storeLogo(CompanyLogoRequest $request): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->setLogo($request->logo())));
    }

    public function destroyLogo(): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->clearLogo()));
    }
}
