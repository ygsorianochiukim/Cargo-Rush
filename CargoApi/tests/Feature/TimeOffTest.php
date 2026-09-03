<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;

/**
 * Leave and undertime.
 *
 * The rules worth pinning are the ones that only bite after somebody has been
 * paid on them: a counted total that disagrees with its own dates, two leaves
 * booked over each other, and a decision made twice by two different people.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);
    $this->approver = User::factory()->create(['role' => Role::Administrator, 'name' => 'Ana Cruz']);

    $this->employee = Employee::create([
        'employee_no' => 'EMP-0001',
        'first_name' => 'Marco',
        'last_name' => 'Reyes',
        'position' => 'Driver',
        'hired_on' => '2026-03-01',
        'contact' => '0917 555 0101',
    ]);

    $this->ask = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/leave', [
            'employee_id' => $this->employee->id,
            'type' => 'vacation',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-18',
            'reason' => 'Family trip',
            ...$overrides,
        ]);

    $this->askUndertime = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/undertime', [
            'employee_id' => $this->employee->id,
            'date' => '2026-09-10',
            'from_time' => '15:30',
            'to_time' => '17:00',
            'reason' => 'Clinic appointment',
            ...$overrides,
        ]);
});

describe('asking for leave', function (): void {
    it('counts the days from the dates rather than taking a typed total', function (): void {
        $response = ($this->ask)()->assertCreated();

        // 14th to 18th inclusive is five days, not four.
        expect($response->json('data.days'))->toEqual(5.0);
        expect($response->json('data.status'))->toBe('pending');
        expect($response->json('data.type_label'))->toBe('Vacation');
        expect($response->json('data.paid'))->toBeTrue();
    });

    it('counts a single day as one, not zero', function (): void {
        $response = ($this->ask)(['starts_on' => '2026-09-14', 'ends_on' => '2026-09-14'])
            ->assertCreated();

        expect($response->json('data.days'))->toEqual(1.0);
    });

    it('marks unpaid leave as unpaid', function (): void {
        expect(($this->ask)(['type' => 'unpaid'])->json('data.paid'))->toBeFalse();
    });

    it('refuses leave that ends before it starts', function (): void {
        ($this->ask)(['starts_on' => '2026-09-18', 'ends_on' => '2026-09-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_on');
    });

    it('refuses leave overlapping something already booked', function (): void {
        ($this->ask)()->assertCreated();

        ($this->ask)(['starts_on' => '2026-09-16', 'ends_on' => '2026-09-20'])->assertStatus(422);
    });

    it('treats a pending request as a clash, not just an approved one', function (): void {
        ($this->ask)()->assertCreated();

        // Still undecided. Approving both afterwards is how a driver ends up
        // rostered while on holiday.
        ($this->ask)(['starts_on' => '2026-09-18', 'ends_on' => '2026-09-19'])->assertStatus(422);
    });

    it('allows leave once a clashing request has been withdrawn', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/hr/leave/$id/withdraw")->assertOk();

        ($this->ask)()->assertCreated();
    });

    it('lets a different employee take the same dates', function (): void {
        ($this->ask)()->assertCreated();

        $other = Employee::create([
            'employee_no' => 'EMP-0002',
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position' => 'Helper',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ]);

        ($this->ask)(['employee_id' => $other->id])->assertCreated();
    });

    it('recounts the days when the dates are corrected', function (): void {
        $id = ($this->ask)()->json('data.id');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/hr/leave/$id", [
                'employee_id' => $this->employee->id,
                'type' => 'vacation',
                'starts_on' => '2026-09-14',
                'ends_on' => '2026-09-15',
                'reason' => 'Family trip',
            ])
            ->assertOk();

        expect($response->json('data.days'))->toEqual(2.0);
    });
});

describe('deciding', function (): void {
    it('records who decided and when, not just the outcome', function (): void {
        $id = ($this->ask)()->json('data.id');

        $response = $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'approved'])
            ->assertOk();

        expect($response->json('data.status'))->toBe('approved');
        expect($response->json('data.decided_by_name'))->toBe('Ana Cruz');
        expect($response->json('data.decided_at'))->not->toBeNull();
    });

    it('keeps a rejection note, which is the whole point of one', function (): void {
        $id = ($this->ask)()->json('data.id');

        $response = $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", [
                'decision' => 'rejected',
                'note' => 'Peak season — try October.',
            ])
            ->assertOk();

        expect($response->json('data.status'))->toBe('rejected');
        expect($response->json('data.decision_note'))->toBe('Peak season — try October.');
    });

    it('refuses to decide the same request twice', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'approved'])
            ->assertOk();

        // Re-approving would move `decided_at` and lose who actually made
        // the call.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'rejected'])
            ->assertStatus(422);
    });

    it('will not accept pending as a decision', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('decision');
    });

    it('refuses to edit a request once it has been decided', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'approved']);

        // Otherwise three days signed off could quietly become ten.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/hr/leave/$id", [
                'employee_id' => $this->employee->id,
                'type' => 'vacation',
                'starts_on' => '2026-09-14',
                'ends_on' => '2026-09-30',
                'reason' => 'Family trip',
            ])
            ->assertStatus(422);
    });

    it('lets an approved leave still be withdrawn, because plans change', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'approved']);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/hr/leave/$id/withdraw")
            ->assertOk();

        expect($response->json('data.status'))->toBe('cancelled');
    });

    it('has nothing to withdraw on a rejected request', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'rejected']);

        $this->actingAs($this->admin)->postJson("/api/v1/hr/leave/$id/withdraw")->assertStatus(422);
    });
});

describe('undertime', function (): void {
    it('works the hours out from the two times', function (): void {
        $response = ($this->askUndertime)()->assertCreated();

        expect($response->json('data.hours'))->toEqual(1.5);
        expect($response->json('data.from_time'))->toBe('15:30');
        expect($response->json('data.to_time'))->toBe('17:00');
    });

    it('refuses an end time before the start', function (): void {
        ($this->askUndertime)(['from_time' => '17:00', 'to_time' => '15:30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_time');
    });

    it('refuses a time it cannot read', function (): void {
        ($this->askUndertime)(['from_time' => 'half three'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_time');
    });

    it('allows two on the same day, unlike leave', function (): void {
        // A morning errand and an afternoon one are two real requests; there
        // is no range to overlap.
        ($this->askUndertime)()->assertCreated();
        ($this->askUndertime)(['from_time' => '08:00', 'to_time' => '09:00'])->assertCreated();
    });

    it('is decided the same way leave is', function (): void {
        $id = ($this->askUndertime)()->json('data.id');

        $response = $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/undertime/$id/decision", ['decision' => 'approved'])
            ->assertOk();

        expect($response->json('data.status'))->toBe('approved');
        expect($response->json('data.decided_by_name'))->toBe('Ana Cruz');
    });
});

describe('the queue', function (): void {
    it('counts leave and undertime together, because the desk works one queue', function (): void {
        ($this->ask)();
        ($this->askUndertime)();

        $overview = $this->actingAs($this->admin)->getJson('/api/v1/hr/time-off')->assertOk();

        expect($overview->json('data.awaiting_decision'))->toBe(2);
    });

    it('drops out of the queue once decided', function (): void {
        $id = ($this->ask)()->json('data.id');

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$id/decision", ['decision' => 'approved']);

        expect($this->actingAs($this->admin)->getJson('/api/v1/hr/time-off')->json('data.awaiting_decision'))
            ->toBe(0);
    });

    it('badges the sidebar with what is still waiting', function (): void {
        ($this->ask)();
        ($this->askUndertime)();

        $nav = $this->actingAs($this->admin)->getJson('/api/v1/navigation');
        $row = collect($nav->json('data'))->firstWhere('key', 'time-off');

        expect($row['badge'])->toBe(2);
        expect($row['group'])->toBe('HR');
    });

    it('counts who is away today, and only from approved leave', function (): void {
        $pending = ($this->ask)([
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
        ])->json('data.id');

        expect($this->actingAs($this->admin)->getJson('/api/v1/hr/time-off')->json('data.away_today'))
            ->toBe(0);

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$pending/decision", ['decision' => 'approved']);

        expect($this->actingAs($this->admin)->getJson('/api/v1/hr/time-off')->json('data.away_today'))
            ->toBe(1);
    });

    it('finds a leave that overlaps the window rather than only ones starting in it', function (): void {
        // Runs from last month into this one. A start-date filter would hide
        // it, and it is still this month's problem.
        ($this->ask)(['starts_on' => '2026-08-28', 'ends_on' => '2026-09-03']);

        $found = $this->actingAs($this->admin)
            ->getJson('/api/v1/hr/leave?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        expect($found->json('data'))->toHaveCount(1);
    });

    it('lists only what is still open when asked', function (): void {
        $decided = ($this->ask)()->json('data.id');
        ($this->ask)(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hr/leave/$decided/decision", ['decision' => 'approved']);

        $open = $this->actingAs($this->admin)->getJson('/api/v1/hr/leave?open=1')->assertOk();

        expect($open->json('data'))->toHaveCount(1);
        expect($open->json('data.0.status'))->toBe('pending');
    });

    it('goes with the employee when their record is deleted', function (): void {
        ($this->ask)();

        $this->actingAs($this->admin)->deleteJson("/api/v1/employees/{$this->employee->id}");

        // The employee is soft-deleted, so their requests are still there —
        // an approved leave is part of the record, not scratch data.
        expect(LeaveRequest::count())->toBe(1);
    });
});
