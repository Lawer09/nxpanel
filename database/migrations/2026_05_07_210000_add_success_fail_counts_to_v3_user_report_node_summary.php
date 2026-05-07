<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v3_user_report_node_summary', function (Blueprint $table) {
            if (!Schema::hasColumn('v3_user_report_node_summary', 'success_count')) {
                $table->unsignedInteger('success_count')->default(0)->after('compute_count')->comment('成功次数(status=success)');
            }
            if (!Schema::hasColumn('v3_user_report_node_summary', 'fail_count')) {
                $table->unsignedInteger('fail_count')->default(0)->after('success_count')->comment('失败次数(status=failed)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v3_user_report_node_summary', function (Blueprint $table) {
            if (Schema::hasColumn('v3_user_report_node_summary', 'fail_count')) {
                $table->dropColumn('fail_count');
            }
            if (Schema::hasColumn('v3_user_report_node_summary', 'success_count')) {
                $table->dropColumn('success_count');
            }
        });
    }
};
