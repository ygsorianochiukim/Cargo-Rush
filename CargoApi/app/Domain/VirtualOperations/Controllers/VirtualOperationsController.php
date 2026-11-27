<?php

declare(strict_types=1);

namespace App\Domain\VirtualOperations\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\VirtualOperations\Services\OfficeAgentService;
use App\Domain\VirtualOperations\Services\VirtualOperationsService;
use App\Domain\VirtualOperations\Support\OperationsLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VirtualOperationsController extends ApiController
{
    public function show(Tenant $tenant, VirtualOperationsService $operations): JsonResponse
    {
        return $this->payload($operations->snapshot($this->company($tenant)));
    }

    public function ask(Request $request, Tenant $tenant, OfficeAgentService $agents): JsonResponse
    {
        $input = $request->validate([
            'agent' => ['required', Rule::in(OfficeAgentService::DESKS)],
            'question' => ['required', 'string', 'max:500'],
        ]);

        return $this->payload(['answer' => $agents->answer($this->company($tenant), $input['agent'], $input['question'])]);
    }

    public function updateLayout(Request $request, Tenant $tenant, VirtualOperationsService $operations): JsonResponse
    {
        abort_unless($request->user()->systemRole() === Role::Administrator, 403);
        $roomIds = array_filter(array_column((array) $request->input('rooms', []), 'id'), 'is_string');
        $assignableRooms = array_diff([...array_keys(OperationsLayout::ROOMS), ...$roomIds], ['ai']);

        $input = $request->validate([
            'rooms' => ['required', 'array', 'list', 'max:40'],
            'rooms.*' => ['required', 'array:id,name'],
            'rooms.*.id' => ['required', 'string', 'distinct', 'regex:/^(?:'.implode('|', array_keys(OperationsLayout::ROOMS)).'|custom-[a-z0-9-]{1,36})$/D'],
            'rooms.*.name' => ['required', 'string', 'max:48'],
            'rules' => ['present', 'array', 'list', 'max:2000'],
            'rules.*' => ['required', 'array:key,room'],
            'rules.*.key' => ['required', 'string', 'distinct:strict', 'regex:/^(?:position:[0-9A-HJKMNP-TV-Z]{26}|title:.{1,255})$/iuD'],
            'rules.*.room' => ['required', Rule::in($assignableRooms)],
        ]);

        $company = $this->company($tenant);
        $company->virtual_operations_layout = [
            'rooms' => OperationsLayout::rooms(['rooms' => array_column($input['rooms'], 'name', 'id')]),
            'rules' => array_column($input['rules'], 'room', 'key'),
        ];
        $company->save();

        return $this->payload($operations->snapshot($company));
    }

    public function updateAppearance(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->systemRole() === Role::Administrator, 403);
        abort_unless($employee->isActive(), 404);

        $appearance = $request->validate([
            'hair' => ['required', 'integer', 'between:0,3'],
            'skin' => ['required', 'integer', 'between:0,4'],
            'outfit' => ['required', 'integer', 'between:0,5'],
            'accessory' => ['required', 'integer', 'between:0,3'],
        ]);

        $employee->virtual_appearance = array_map('intval', $appearance);
        $employee->save();

        return $this->payload(OperationsLayout::appearance($employee));
    }

    private function company(Tenant $tenant): Company
    {
        $company = $tenant->company();
        abort_if($company === null, 403);

        return $company;
    }
}
