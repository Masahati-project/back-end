<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('broadcasts', 'recipients_count')) {
                $table->integer('recipients_count')->nullable()->default(0)->after('total');
            }
            if (! Schema::hasColumn('broadcasts', 'in_app_created_count')) {
                $table->integer('in_app_created_count')->nullable()->default(0)->after('recipients_count');
            }
            if (! Schema::hasColumn('broadcasts', 'email_queued_count')) {
                $table->integer('email_queued_count')->nullable()->default(0)->after('in_app_created_count');
            }
            if (! Schema::hasColumn('broadcasts', 'email_sent_count')) {
                $table->integer('email_sent_count')->nullable()->default(0)->after('email_queued_count');
            }
            if (! Schema::hasColumn('broadcasts', 'email_failed_count')) {
                $table->integer('email_failed_count')->nullable()->default(0)->after('email_sent_count');
            }
            if (! Schema::hasColumn('broadcasts', 'email_skipped_no_address_count')) {
                $table->integer('email_skipped_no_address_count')->nullable()->default(0)->after('email_failed_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['recipients_count', 'in_app_created_count', 'email_queued_count', 'email_sent_count', 'email_failed_count', 'email_skipped_no_address_count']);
        });
    }
};
