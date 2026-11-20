<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedSmallInteger('guests')->nullable();
            $table->string('status', 20)->default('booked'); // booked|arrived|completed|cancelled
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'starts_at']);
        });

        Schema::create('category_kitchen_station', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'kitchen_station_id']);
        });

        // One run of "import from the old system": what was uploaded, how far it got, what it found.
        Schema::create('legacy_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');                 // uploaded file name / database name
            $table->string('package_path')->nullable();    // extracted package directory
            $table->string('status', 20)->default('pending'); // pending|running|completed|failed
            $table->string('stage', 40)->nullable();
            $table->json('manifest')->nullable();          // tables, row counts, columns, backup date
            $table->json('options')->nullable();           // user → role, store → location… chosen before running
            $table->json('progress')->nullable();          // stage => {done, total}
            $table->json('report')->nullable();            // counts, warnings and the reconciliation
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        // Every row of every non-empty table of the old database, kept as it was — nothing is lost,
        // even what has no place in the new system (history, login log, report designs…).
        Schema::create('legacy_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 80);
            $table->string('record_key', 191);             // primary-key values joined by "|" (or a row hash)
            $table->json('data');
            $table->foreignId('legacy_import_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['source_table', 'record_key']);
        });

        // Old record → new record, so the import is re-runnable and every document can show its old history.
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_table', 80);
            $table->string('legacy_key', 191);
            $table->string('new_type', 60);
            $table->unsignedBigInteger('new_id');
            $table->timestamp('migrated_at')->nullable();
            $table->unique(['legacy_table', 'legacy_key']);
            $table->index(['new_type', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
        Schema::dropIfExists('legacy_records');
        Schema::dropIfExists('legacy_imports');
        Schema::dropIfExists('category_kitchen_station');
        Schema::dropIfExists('bookings');
    }
};
