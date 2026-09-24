<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Requests\PayrollCutoffRequestRequest;
use App\Domain\Payroll\Resources\PayrollCutoffRequestResource;
use App\Domain\Payroll\Services\PayrollCutoffRequestService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking for the pay cutoff to be moved, and deciding it.
 *
 * The two halves sit behind **different permissions on purpose**, and that
 * split is the reason the endpoint exists at all:
 *
 *   Filing is `payroll.manage` — whoever builds and approves the runs. They are
 *   the person who notices that the fortnight the system pays is not the
 *   fortnight the yard works.
 *
 *   Deciding is `company.manage` — whoever holds the company's settings, which
 *   out of the box is the administrator alone. Moving the cutoff reshapes every
 *   future pay period, and that is not a field on the screen of whoever happens
 *   to be running payroll this week.
 *
 * Approving and declining are verbs rather than a status PATCH, for the reason
 * approving a pay run is: **approving applies the change**. A status field that
 * moved a company's payroll calendar when set to a particular value would hide
 * what actually happened.
 */
class PayrollCutoffRequestController extends ApiController
{
    public function __construct(private readonly PayrollCutoffRequestService $requests) {}

    /**
     * `GET payroll/cutoff-requests` — the log, newest first.
     *
     * Not paginated: a firm files one of these every few years. `?status=` to
     * narrow, which is what a screen showing only what is outstanding uses.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $rows = $this->requests->all(is_string($status) && $status !== '' ? $status : null);

        return $this->collection(PayrollCutoffRequestResource::collection($rows), $rows);
    }

    /**
     * `GET payroll/cutoff-requests/pending` — the one waiting, or nothing.
     *
     * Its own endpoint rather than a filter on the list, because it answers a
     * different question: every screen that offers the request button needs to
     * know whether to offer it, and one that has to fetch and scan a log to
     * find out is a screen that fetches a log on every page load.
     *
     * Before `{request}` in the route file, so `pending` is never read as an id.
     */
    public function pending(): JsonResponse
    {
        $pending = $this->requests->pending();

        return $pending === null
            ? $this->payload([])
            : $this->item(new PayrollCutoffRequestResource($pending));
    }

    /** `POST payroll/cutoff-requests` — ask, with a reason. */
    public function store(PayrollCutoffRequestRequest $request): JsonResponse
    {
        return $this->item(
            new PayrollCutoffRequestResource(
                $this->requests->file($request->toAttributes(), $this->user($request)),
            ),
            status: 201,
        );
    }

    /**
     * `POST payroll/cutoff-requests/{request}/approve` — and this is what moves
     * the cutoff.
     *
     * Re-validated inside the service before anything is written: the shape of
     * a cutoff cannot change under a pending request, but whether a draft pay
     * run is open certainly can, in both directions.
     */
    public function approve(Request $httpRequest, PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource(
            $this->requests->approve($request, $this->user($httpRequest), $this->note($httpRequest)),
        ));
    }

    /** `POST payroll/cutoff-requests/{request}/decline` — no, and why. */
    public function decline(Request $httpRequest, PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource(
            $this->requests->decline($request, $this->user($httpRequest), $this->note($httpRequest)),
        ));
    }

    /**
     * `DELETE payroll/cutoff-requests/{request}` — the asker taking it back.
     *
     * A delete in the route because that is the verb the screen offers, and a
     * *withdrawal* in the data because the row is worth keeping: a request
     * somebody thought better of and one an administrator refused are different
     * facts, and only one is worth noticing a pattern in.
     *
     * On `payroll.manage`, the filing permission — withdrawing is the asker
     * changing their mind, not a decision.
     */
    public function withdraw(PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource($this->requests->withdraw($request)));
    }

    /** The decision note, off the body. Optional on both verbs. */
    private function note(Request $request): ?string
    {
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $note = trim((string) ($validated['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /** Who asked or decided. Stamped from the token, never a payload. */
    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
