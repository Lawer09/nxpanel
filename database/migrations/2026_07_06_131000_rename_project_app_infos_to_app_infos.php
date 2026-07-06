<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename legacy app info storage and make app id the stable key.
     */
    public function up(): void
    {
        if (!Schema::hasTable('app_infos') && Schema::hasTable('project_app_infos')) {
            Schema::rename('project_app_infos', 'app_infos');
        }

        if (!Schema::hasTable('app_infos')) {
            $this->createAppInfosTable();
        }

        if (!Schema::hasColumn('app_infos', 'download_data')) {
            Schema::table('app_infos', function (Blueprint $table): void {
                $table->json('download_data')->nullable()->after('download_count')->comment('Application download data');
            });
        }

        if (Schema::hasColumn('app_infos', 'project_code')) {
            $this->deduplicateAppInfosByAppId();
            Schema::table('app_infos', function (Blueprint $table): void {
                if ($this->indexExists('app_infos', 'uk_project_app_info')) {
                    $table->dropUnique('uk_project_app_info');
                }
                if ($this->indexExists('app_infos', 'idx_pai_project_code')) {
                    $table->dropIndex('idx_pai_project_code');
                }
                if ($this->indexExists('app_infos', 'idx_pai_app_id')) {
                    $table->dropIndex('idx_pai_app_id');
                }
                if ($this->indexExists('app_infos', 'idx_pai_enabled')) {
                    $table->dropIndex('idx_pai_enabled');
                }
                $table->dropColumn('project_code');
            });
        }

        Schema::table('app_infos', function (Blueprint $table): void {
            if (
                !$this->indexExists('app_infos', 'uk_app_infos_app_id')
                && !$this->indexExistsForColumns('app_infos', ['app_id'], true)
            ) {
                $table->unique('app_id', 'uk_app_infos_app_id');
            }
            if (
                !$this->indexExists('app_infos', 'idx_app_infos_enabled')
                && !$this->indexExistsForColumns('app_infos', ['enabled'])
            ) {
                $table->index('enabled', 'idx_app_infos_enabled');
            }
        });
    }

    /**
     * Restore the previous table shape for rollback.
     */
    public function down(): void
    {
        if (!Schema::hasTable('app_infos')) {
            return;
        }

        Schema::table('app_infos', function (Blueprint $table): void {
            if ($this->indexExists('app_infos', 'uk_app_infos_app_id')) {
                $table->dropUnique('uk_app_infos_app_id');
            }
            if ($this->indexExists('app_infos', 'idx_app_infos_enabled')) {
                $table->dropIndex('idx_app_infos_enabled');
            }
        });

        if (!Schema::hasColumn('app_infos', 'project_code')) {
            Schema::table('app_infos', function (Blueprint $table): void {
                $table->string('project_code', 100)->nullable()->after('id')->comment('Project code');
            });
        }

        if (!Schema::hasTable('project_app_infos')) {
            Schema::rename('app_infos', 'project_app_infos');
        }

        Schema::table('project_app_infos', function (Blueprint $table): void {
            if (!$this->indexExists('project_app_infos', 'uk_project_app_info')) {
                $table->unique(['project_code', 'app_id'], 'uk_project_app_info');
            }
            if (!$this->indexExists('project_app_infos', 'idx_pai_project_code')) {
                $table->index('project_code', 'idx_pai_project_code');
            }
            if (!$this->indexExists('project_app_infos', 'idx_pai_app_id')) {
                $table->index('app_id', 'idx_pai_app_id');
            }
            if (!$this->indexExists('project_app_infos', 'idx_pai_enabled')) {
                $table->index('enabled', 'idx_pai_enabled');
            }
        });
    }

    /**
     * Create app info storage directly when the legacy table is absent.
     */
    private function createAppInfosTable(): void
    {
        Schema::create('app_infos', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('app_id', 255)->comment('Application id');
            $table->string('app_name', 191)->nullable()->comment('Application name');
            $table->string('platform', 50)->nullable()->comment('Application platform');
            $table->unsignedBigInteger('download_count')->default(0)->comment('Application download count');
            $table->json('download_data')->nullable()->comment('Application download data');
            $table->string('icon_url', 255)->nullable()->comment('Application icon URL');
            $table->string('chart_url', 255)->nullable()->comment('Application chart image URL');
            $table->json('image_urls')->nullable()->comment('Additional application image URLs');
            $table->string('store_url', 255)->nullable()->comment('Application store URL');
            $table->tinyInteger('enabled')->default(1)->comment('Whether the app info is enabled');
            $table->string('remark', 255)->nullable()->comment('Remark');
            $table->timestamps();
        });
    }

    /**
     * Keep one row per app id before adding the new unique key.
     */
    private function deduplicateAppInfosByAppId(): void
    {
        $duplicateAppIds = DB::table('app_infos')
            ->select('app_id')
            ->groupBy('app_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('app_id');

        foreach ($duplicateAppIds as $appId) {
            $keepId = DB::table('app_infos')
                ->where('app_id', $appId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('app_infos')
                ->where('app_id', $appId)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }

    /**
     * Check whether an index exists on the current connection.
     */
    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(static fn (array $item): bool => ($item['name'] ?? null) === $index);
    }

    /**
     * Check whether an index already covers the requested columns.
     */
    private function indexExistsForColumns(string $table, array $columns, ?bool $unique = null): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(static function (array $item) use ($columns, $unique): bool {
                if ($unique !== null && (bool) ($item['unique'] ?? false) !== $unique) {
                    return false;
                }

                return array_values($item['columns'] ?? []) === array_values($columns);
            });
    }
};
