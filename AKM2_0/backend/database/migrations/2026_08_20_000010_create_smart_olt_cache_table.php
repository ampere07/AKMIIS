<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable half of SmartOltReconciliationService's snapshot store.
 *
 * The service already read and wrote this table, guarded by a try/catch, but nothing
 * ever created it — so on every install the guard was swallowing the failure and the
 * snapshots lived only in the framework cache. That was survivable for the inventory
 * and status keys, which are meant to go stale, and expensive for `optical`: it holds
 * the bridge MAC discovered by `onu/get_onu_full_status_info`, one throttled API call
 * per ONU, and losing it to a cache flush meant re-crawling the whole estate to learn
 * MACs that had not changed.
 *
 * With the table present the optical snapshot is written once and kept indefinitely,
 * shared between the web process, the CLI and cron, and the nightly sweep spends the
 * per-ONU call only on ONUs it has never read.
 *
 * `cache_key` is unique because the service writes through `updateOrInsert` — without
 * it a re-run would append a second row for the same key rather than overwrite the
 * first, and the read side would pick up whichever came back first.
 *
 * Guarded on every step: this migration is safe to re-run, and safe on an instance
 * where the table was created by hand before it shipped.
 */
return new class extends Migration
{
    private const TABLE = 'smart_olt_cache';

    private const UNIQUE_INDEX = 'smart_olt_cache_cache_key_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('cache_key', 64)->unique(self::UNIQUE_INDEX);
                // A 4,000-ONU inventory snapshot is well past what TEXT holds.
                $table->longText('data')->nullable();
                // Written explicitly by putCache(); there is no created_at because the
                // row is only ever upserted, never treated as an event.
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        // Table already present — most likely created by hand on an instance that ran
        // the service before this migration existed. Bring it up to contract without
        // touching the rows it is holding.
        if (!Schema::hasColumn(self::TABLE, 'data')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->longText('data')->nullable();
            });
        }

        if (!Schema::hasColumn(self::TABLE, 'updated_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (Schema::hasColumn(self::TABLE, 'cache_key') && !$this->indexExists(self::UNIQUE_INDEX)) {
            // Duplicates would make the unique index refuse to build, and a duplicate
            // here is a stale copy of the same key. Keep the newest row per key.
            $this->dropDuplicateKeys();

            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('cache_key', self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Dropping this table costs every stored bridge MAC, and re-earning them is
        // one throttled API call per ONU across the whole estate. The rollback is
        // deliberately a no-op; remove the table by hand if that is really intended.
    }

    /**
     * Keep the highest id per cache_key so the unique index can be created.
     */
    private function dropDuplicateKeys(): void
    {
        $keep = Schema::getConnection()
            ->table(self::TABLE)
            ->selectRaw('MAX(id) as id')
            ->groupBy('cache_key')
            ->pluck('id')
            ->all();

        if ($keep === []) {
            return;
        }

        Schema::getConnection()
            ->table(self::TABLE)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Laravel 9 has no portable "does this index exist"; ask the schema manager.
     */
    private function indexExists(string $name): bool
    {
        try {
            return Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableDetails(self::TABLE)
                ->hasIndex($name);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
