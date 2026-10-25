<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'title')) {
                $table->string('title')->nullable()->after('type');
            }
            if (! Schema::hasColumn('notifications', 'body')) {
                $table->text('body')->nullable()->after('title');
            }
            if (! Schema::hasColumn('notifications', 'link')) {
                $table->string('link')->nullable()->after('body');
            }
            if (! Schema::hasColumn('notifications', 'read')) {
                $table->boolean('read')->nullable()->after('is_read');
            }
            if (! Schema::hasColumn('notifications', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('read');
            }
            if (! Schema::hasColumn('notifications', 'broadcast_id')) {
                $table->unsignedBigInteger('broadcast_id')->nullable()->after('read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['title', 'body', 'link', 'read', 'read_at', 'broadcast_id']);
        });
    }
};
