<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photograph taken at the door.
 *
 * Proof of delivery was a reference the driver typed — which is a number they
 * had to be given from somewhere, and in practice was invented in the cab. The
 * reference is now assigned by the system (`POD-#####`, like a trip's `CR-`
 * series), and the actual proof is this: an image, plus the consignee's name
 * typed as the signature.
 *
 * A path, not the bytes. Images live on the configured disk (`config/cargo.php`)
 * so the database stays small and the file can be served straight from storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->string('pod_image_path')->nullable()->after('pod_ref');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropColumn('pod_image_path');
        });
    }
};
