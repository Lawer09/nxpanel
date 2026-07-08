<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create automatic IP allowlist rules.
     */
    public function up(): void
    {
        Schema::create('ip_allowlist_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191)->comment('Rule name');
            $table->boolean('enabled')->default(true)->comment('Whether this rule is enabled');
            $table->json('countries')->nullable()->comment('Matched country codes');
            $table->json('project_codes')->nullable()->comment('Matched project codes');
            $table->json('package_names')->nullable()->comment('Matched package names');
            $table->string('reason', 500)->nullable()->comment('Allow reason');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the rule');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last updated the rule');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index('enabled');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    /**
     * Drop automatic IP allowlist rules.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_allowlist_rules');
    }
};
