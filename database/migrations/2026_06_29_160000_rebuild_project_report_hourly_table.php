<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_report_hourly');

        Schema::create('project_report_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('report_date')->comment('Report date');
            $table->unsignedTinyInteger('hour')->comment('Hour, 0-23');
            $table->string('project_code', 100)->comment('Project code');
            $table->string('country', 50)->default('XX')->comment('Country, empty value normalized to XX');

            $table->unsignedInteger('dau_users')->default(0)->comment('Hourly active users');
            $table->unsignedInteger('new_users')->default(0)->comment('Hourly new users');
            $table->unsignedInteger('report_new_users')->default(0)->comment('Hourly first-report users');
            $table->unsignedInteger('fb_new_users')->default(0)->comment('Firebase new users, reserved');
            $table->unsignedInteger('fb_dau_users')->default(0)->comment('Firebase active users, reserved');

            $table->decimal('ad_revenue', 20, 6)->default(0)->comment('Hourly ad revenue');
            $table->bigInteger('ad_requests')->default(0)->comment('Hourly ad requests');
            $table->bigInteger('ad_matched_requests')->default(0)->comment('Hourly matched ad requests');
            $table->bigInteger('ad_impressions')->default(0)->comment('Hourly ad impressions');
            $table->bigInteger('ad_clicks')->default(0)->comment('Hourly ad clicks');
            $table->decimal('ad_ecpm', 20, 6)->nullable()->comment('Hourly eCPM');
            $table->decimal('ad_ctr', 12, 6)->nullable()->comment('Hourly CTR');
            $table->decimal('ad_match_rate', 12, 6)->nullable()->comment('Hourly match rate');
            $table->decimal('ad_show_rate', 12, 6)->nullable()->comment('Hourly show rate');

            $table->decimal('ad_spend_cost', 20, 6)->default(0)->comment('Hourly ad spend cost, reserved');
            $table->decimal('ad_spend_cpi', 20, 6)->nullable()->comment('Hourly CPI, reserved');
            $table->decimal('ad_spend_cpc', 20, 6)->nullable()->comment('Hourly CPC, reserved');
            $table->decimal('ad_spend_cpm', 20, 6)->nullable()->comment('Hourly CPM, reserved');

            $table->decimal('traffic_usage_mb', 20, 6)->default(0)->comment('Hourly traffic usage MB');
            $table->decimal('traffic_cost', 20, 6)->default(0)->comment('Hourly traffic cost');
            $table->decimal('profit', 20, 6)->default(0)->comment('Hourly profit');
            $table->decimal('roi', 20, 6)->nullable()->comment('Hourly ROI');

            $table->timestamps();

            $table->unique(['report_date', 'hour', 'project_code', 'country'], 'uk_project_report_hourly_dim');
            $table->index(['report_date', 'project_code'], 'idx_project_report_hourly_date_project');
            $table->index(['report_date', 'country'], 'idx_project_report_hourly_date_country');
            $table->index(['project_code', 'report_date', 'hour'], 'idx_project_report_hourly_project_date_hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_report_hourly');

        Schema::create('project_report_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date')->comment('Report date');
            $table->unsignedTinyInteger('hour')->comment('Hour, 0-23');
            $table->string('project_code', 100)->comment('Project code');
            $table->string('country', 50)->default('XX')->comment('Country');
            $table->unsignedInteger('install_users')->default(0)->comment('Hourly install users');
            $table->unsignedInteger('dau_users')->default(0)->comment('Hourly active users');
            $table->decimal('ad_revenue', 20, 6)->default(0)->comment('Hourly allocated ad revenue');
            $table->decimal('ad_spend_cost', 20, 6)->default(0)->comment('Hourly allocated ad spend');
            $table->decimal('ros', 20, 6)->nullable()->comment('ROS');
            $table->timestamps();

            $table->unique(['date', 'hour', 'project_code', 'country'], 'uk_project_report_hourly_dim');
            $table->index(['date', 'project_code'], 'idx_project_report_hourly_date_project');
            $table->index(['date', 'country'], 'idx_project_report_hourly_date_country');
            $table->index(['project_code', 'date', 'hour'], 'idx_project_report_hourly_project_date_hour');
        });
    }
};
