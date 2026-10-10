<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_user', function (Blueprint $table): void {
            $table->id();

            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['customer_id', 'user_id']);
            $table->index('user_id');
        });

        DB::table('users')
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->select(['id', 'customer_id'])
            ->chunk(500, function ($users): void {
                DB::table('customer_user')->insertOrIgnore(
                    $users->map(static fn ($user): array => [
                        'customer_id' => $user->customer_id,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');
    }
};
