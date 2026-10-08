<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("offers", function (Blueprint $table) {
            $table->id();
            $table->foreignId("special_request_id")->constrained("special_requests")->onDelete("cascade");
            $table->foreignId("workspace_id")->constrained("workspaces")->onDelete("cascade");
            $table->decimal("price_per_hour", 12, 2);
            $table->string("currency", 3)->default("USD");
            $table->integer("duration_hours");
            $table->string("location")->nullable();
            $table->text("notes")->nullable();
            $table->decimal("rating", 3, 2)->nullable();
            $table->enum("status", ["pending", "accepted", "rejected"])->default("pending");
            $table->timestamp("created_at");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("offers");
    }
};
