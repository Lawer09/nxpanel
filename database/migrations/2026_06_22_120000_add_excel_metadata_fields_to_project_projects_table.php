<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add project metadata fields sourced from the project tracking spreadsheet.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_projects')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            if (!Schema::hasColumn('project_projects', 'adspower_env')) {
                $table->string('adspower_env', 100)->nullable()->after('ad_status')->comment('Adspower环境');
            }
            if (!Schema::hasColumn('project_projects', 'developer_gmail')) {
                $table->string('developer_gmail', 191)->nullable()->after('adspower_env')->comment('开发者Gmail');
                $table->index('developer_gmail', 'idx_pp_developer_gmail');
            }
            if (!Schema::hasColumn('project_projects', 'app_name')) {
                $table->string('app_name', 191)->nullable()->after('developer_gmail')->comment('应用名称');
            }
            if (!Schema::hasColumn('project_projects', 'package_name')) {
                $table->string('package_name', 191)->nullable()->after('app_name')->comment('项目包名');
                $table->index('package_name', 'idx_pp_package_name');
            }
            if (!Schema::hasColumn('project_projects', 'domain_info_status')) {
                $table->string('domain_info_status', 50)->nullable()->after('package_name')->comment('域名信息状态');
            }
            if (!Schema::hasColumn('project_projects', 'admob_pub_id')) {
                $table->string('admob_pub_id', 100)->nullable()->after('domain_info_status')->comment('Admob pub id');
            }
            if (!Schema::hasColumn('project_projects', 'domain_url')) {
                $table->string('domain_url', 255)->nullable()->after('admob_pub_id')->comment('域名');
            }
            if (!Schema::hasColumn('project_projects', 'privacy_policy_url')) {
                $table->string('privacy_policy_url', 255)->nullable()->after('domain_url')->comment('隐私协议');
            }
            if (!Schema::hasColumn('project_projects', 'terms_url')) {
                $table->string('terms_url', 255)->nullable()->after('privacy_policy_url')->comment('服务条款');
            }
            if (!Schema::hasColumn('project_projects', 'facebook_info_status')) {
                $table->string('facebook_info_status', 50)->nullable()->after('terms_url')->comment('FB信息状态');
            }
            if (!Schema::hasColumn('project_projects', 'facebook_app_id')) {
                $table->string('facebook_app_id', 100)->nullable()->after('facebook_info_status')->comment('Facebook应用ID');
            }
            if (!Schema::hasColumn('project_projects', 'facebook_app_token')) {
                $table->string('facebook_app_token', 255)->nullable()->after('facebook_app_id')->comment('Facebook应用Token');
            }
            if (!Schema::hasColumn('project_projects', 'facebook_key_hash')) {
                $table->string('facebook_key_hash', 255)->nullable()->after('facebook_app_token')->comment('Facebook秘钥散列');
            }
            if (!Schema::hasColumn('project_projects', 'facebook_class_name')) {
                $table->string('facebook_class_name', 191)->nullable()->after('facebook_key_hash')->comment('Facebook类名');
            }
            if (!Schema::hasColumn('project_projects', 'admob_account_status')) {
                $table->string('admob_account_status', 50)->nullable()->after('facebook_class_name')->comment('Admob账号状态');
            }
            if (!Schema::hasColumn('project_projects', 'admob_app_id')) {
                $table->string('admob_app_id', 100)->nullable()->after('admob_account_status')->comment('Admob应用ID');
            }
            if (!Schema::hasColumn('project_projects', 'admob_ad_ids')) {
                $table->text('admob_ad_ids')->nullable()->after('admob_app_id')->comment('Admob广告ID');
            }
            if (!Schema::hasColumn('project_projects', 'admob_app_ads_txt')) {
                $table->text('admob_app_ads_txt')->nullable()->after('admob_ad_ids')->comment('Admob app-ads.txt');
            }
            if (!Schema::hasColumn('project_projects', 'firebase_config_note')) {
                $table->longText('firebase_config_note')->nullable()->after('admob_app_ads_txt')->comment('firebase配置说明');
            }
            if (!Schema::hasColumn('project_projects', 'yandex_account')) {
                $table->string('yandex_account', 191)->nullable()->after('firebase_config_note')->comment('Yandex账号');
            }
            if (!Schema::hasColumn('project_projects', 'yandex_ad_ids')) {
                $table->text('yandex_ad_ids')->nullable()->after('yandex_account')->comment('Yandex广告ID');
            }
            if (!Schema::hasColumn('project_projects', 'yandex_app_ads_txt')) {
                $table->text('yandex_app_ads_txt')->nullable()->after('yandex_ad_ids')->comment('Yandex app-ads.txt');
            }
            if (!Schema::hasColumn('project_projects', 'store_page_url')) {
                $table->string('store_page_url', 255)->nullable()->after('yandex_app_ads_txt')->comment('商店页链接');
            }
        });
    }

    /**
     * Remove project metadata fields sourced from the project tracking spreadsheet.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_projects')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            if (Schema::hasColumn('project_projects', 'developer_gmail')) {
                $table->dropIndex('idx_pp_developer_gmail');
            }
            if (Schema::hasColumn('project_projects', 'package_name')) {
                $table->dropIndex('idx_pp_package_name');
            }

            $columns = [
                'adspower_env',
                'developer_gmail',
                'app_name',
                'package_name',
                'domain_info_status',
                'admob_pub_id',
                'domain_url',
                'privacy_policy_url',
                'terms_url',
                'facebook_info_status',
                'facebook_app_id',
                'facebook_app_token',
                'facebook_key_hash',
                'facebook_class_name',
                'admob_account_status',
                'admob_app_id',
                'admob_ad_ids',
                'admob_app_ads_txt',
                'firebase_config_note',
                'yandex_account',
                'yandex_ad_ids',
                'yandex_app_ads_txt',
                'store_page_url',
            ];

            $existingColumns = array_values(array_filter(
                $columns,
                static fn (string $column) => Schema::hasColumn('project_projects', $column)
            ));

            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};
