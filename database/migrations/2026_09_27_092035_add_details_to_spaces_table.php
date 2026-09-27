<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            if (!Schema::hasColumn('spaces', 'title')) {
                $table->string('title')->after('id');
            }
            if (!Schema::hasColumn('spaces', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('spaces', 'price')) {
                $table->decimal('price', 8, 2)->default(0);
            }
            if (!Schema::hasColumn('spaces', 'location')) {
                $table->string('location')->nullable();
            }
            if (!Schema::hasColumn('spaces', 'capacity')) {
                $table->integer('capacity')->nullable();
            }
            if (!Schema::hasColumn('spaces', 'image')) {
                $table->string('image')->nullable();
            }
            if (!Schema::hasColumn('spaces', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('spaces', 'status')) {
                $table->enum('status', ['pending', 'active', 'inactive'])->default('pending');
            }
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            //
        });
    }
};