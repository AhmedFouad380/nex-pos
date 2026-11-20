<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('paper', 20)->default('a4'); // a4|a5|thermal_80|thermal_58
            $table->string('design', 30)->default('classic');
            $table->json('settings')->nullable(); // labels, toggles, header/footer text (typed via DTO)
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('connection', 20)->default('browser'); // browser|network|qz
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('capability_profile', 30)->default('default');
            $table->unsignedSmallInteger('chars_per_line')->default(42);
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('paper')->nullable(); // width, height, rows, cols, margins, gaps (mm)
            $table->boolean('is_continuous')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);  // new_sale|payment_received|payment_reminder|new_quotation|...
            $table->string('channel', 20); // mail|sms|whatsapp
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->boolean('auto_send')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['event', 'channel']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('invoice_layout_id')->references('id')->on('invoice_layouts')->nullOnDelete();
            $table->foreign('receipt_layout_id')->references('id')->on('invoice_layouts')->nullOnDelete();
            $table->foreign('printer_id')->references('id')->on('printers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['invoice_layout_id']);
            $table->dropForeign(['receipt_layout_id']);
            $table->dropForeign(['printer_id']);
        });
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('label_templates');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('invoice_layouts');
    }
};
