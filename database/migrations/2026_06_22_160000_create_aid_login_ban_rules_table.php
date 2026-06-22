<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create custom AID login ban detection rules.
     */
    public function up(): void
    {
        Schema::create('aid_login_ban_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->comment('Rule name');
            $table->boolean('enabled')->default(true)->comment('Whether this rule is enabled');
            $table->bigInteger('cutoff_at')->comment('Rule effective cutoff timestamp');
            $table->json('weekly_windows')->comment('Active weekly windows');
            $table->json('package_names')->nullable()->comment('Allowed package names');
            $table->json('countries')->nullable()->comment('Allowed country codes');
            $table->string('reason', 500)->nullable()->comment('Ban reason');
            $table->unsignedBigInteger('created_by')->nullable()->comment('Admin user who created the rule');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('Admin user who last updated the rule');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index(['enabled', 'cutoff_at'], 'idx_aid_login_ban_rules_enabled_cutoff');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    /**
     * Drop custom AID login ban detection rules.
     */
    public function down(): void
    {
        Schema::dropIfExists('aid_login_ban_rules');
    }
};
