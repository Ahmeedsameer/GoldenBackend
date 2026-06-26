<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // ── Disaster-recovery guard: drop any orphan from a previous failed run
        Schema::dropIfExists('safes_old');

        if (Schema::hasTable('safes') && Schema::hasColumn('safes', 'balance')) {
            // Old schema exists → migrate it
            // 1. Rename the old table
            Schema::rename('safes', 'safes_old');

            // 2. Drop the FK from safes_old.
            //    MySQL keeps the original constraint name after RENAME TABLE,
            //    so we reference it explicitly via raw SQL.
            try {
                DB::statement('ALTER TABLE safes_old DROP FOREIGN KEY safes_shop_id_foreign');
            } catch (\Throwable $e) {
                // Already dropped or never existed — safe to continue.
            }

            // 3. Create new table
            $this->createSafesTable();

            // 4. Migrate existing rows
            $physicalTypeId = DB::table('safe_types')->where('kind', 'physical')->value('id');
            foreach (DB::table('safes_old')->get() as $old) {
                DB::table('safes')->insert([
                    'shop_id'      => $old->shop_id,
                    'safe_type_id' => $physicalTypeId,
                    'is_active'    => true,
                    'created_at'   => $old->created_at,
                    'updated_at'   => $old->updated_at,
                ]);
            }

            Schema::drop('safes_old');
        } elseif (! Schema::hasTable('safes')) {
            // Table was lost entirely (disaster recovery) — create from scratch
            $this->createSafesTable();
        }
        // else: safes already has the new schema — nothing to do

        // ── Seed company main safe if not already present
        $physicalTypeId = DB::table('safe_types')->where('kind', 'physical')->value('id');
        $exists = DB::table('safes')->whereNull('shop_id')->exists();
        if (! $exists && $physicalTypeId) {
            DB::table('safes')->insert([
                'shop_id'      => null,
                'safe_type_id' => $physicalTypeId,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        // Drop the new-schema safes table (and any orphan staging table)
        Schema::dropIfExists('safes');
        Schema::dropIfExists('safes_old');

        // Recreate the original safes table schema
        Schema::create('safes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function createSafesTable(): void
    {
        Schema::create('safes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                  ->nullable()
                  ->constrained('shops')
                  ->cascadeOnDelete();
            $table->foreignId('safe_type_id')
                  ->constrained('safe_types')
                  ->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
