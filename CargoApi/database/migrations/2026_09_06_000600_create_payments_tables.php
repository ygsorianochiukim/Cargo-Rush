<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('company_id');

            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction')->default('receivable');

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');

            $table->date('paid_on');

            $table->string('method')->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['customer_id', 'paid_on']);
            $table->index(['direction', 'paid_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('company_id');

            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->unique(['payment_id', 'invoice_id']);
            $table->index(['invoice_id']);
        });

        $this->backfillSettledInvoices();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }

    private function backfillSettledInvoices(): void
    {
        DB::table('invoices')
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $paymentId = (string) Str::ulid();
                    $paidAt = $invoice->paid_at;

                    DB::table('payments')->insert([
                        'id' => $paymentId,
                        'company_id' => $invoice->company_id,
                        'customer_id' => $invoice->customer_id,
                        'direction' => $invoice->direction,
                        'amount_cents' => max(0, (int) $invoice->amount_cents - (int) $invoice->withholding_cents),
                        'currency' => $invoice->currency,
                        'paid_on' => substr((string) $paidAt, 0, 10),
                        'method' => 'bank_transfer',
                        'reference' => null,
                        'notes' => 'Recorded before payments were kept separately.',
                        'created_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ]);

                    DB::table('payment_allocations')->insert([
                        'id' => (string) Str::ulid(),
                        'company_id' => $invoice->company_id,
                        'payment_id' => $paymentId,
                        'invoice_id' => $invoice->id,
                        'amount_cents' => max(0, (int) $invoice->amount_cents - (int) $invoice->withholding_cents),
                        'created_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ]);
                }
            });
    }
};
