<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Requests\AccountRequest;
use App\Domain\Accounting\Resources\AccountResource;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends ApiController
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = $this->accounts->all($request->boolean('active'));

        return $this->collection(AccountResource::collection($accounts), $accounts);
    }

    public function types(): JsonResponse
    {
        return $this->payload($this->accounts->types());
    }

    public function show(Account $account): JsonResponse
    {
        return $this->item(new AccountResource($account));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        return $this->item(new AccountResource($this->accounts->create($request->toData())), status: 201);
    }

    public function update(AccountRequest $request, Account $account): JsonResponse
    {
        return $this->item(new AccountResource($this->accounts->update($account, $request->toData())));
    }

    public function destroy(Account $account): JsonResponse
    {
        $deleted = $this->accounts->delete($account);

        return $deleted
            ? $this->noContent()
            : $this->item(new AccountResource($account->refresh()), [
                'retired' => true,
                'message' => sprintf(
                    '%s has postings against it, so it was retired rather than deleted. '
                    .'Its history stays and it takes no new postings.',
                    $account->label(),
                ),
            ]);
    }
}
