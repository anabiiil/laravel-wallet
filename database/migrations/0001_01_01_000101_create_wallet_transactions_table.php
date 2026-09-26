<?php

declare(strict_types=1);

use Anabil\Wallet\Support\MorphKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('wallet.tables.transactions', 'wallet_transactions');
        $walletsTable = config('wallet.tables.wallets', 'wallets');

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $walletsTable): void {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->string('type', 32);
            // Signed minor-unit amount: positive credit, negative debit.
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');

            MorphKey::columns($table, 'reference', 'reference', true);
            MorphKey::columns($table, 'performed_by', 'performed_by', true);

            $table->string('description', 255)->nullable();
            $table->json('meta')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_hash', 64)->nullable();
            $table->uuid('group_uuid')->nullable();
            $table->unsignedBigInteger('reverses_id')->nullable();

            // Rows are append-only, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Indexes are named explicitly (built from the configured table
            // name) so custom table names stay unique across the schema, and
            // are declared before the foreign keys below so MySQL reuses
            // these composite indexes instead of creating its own.
            $table->index(['wallet_id', 'id'], "{$tableName}_wallet_id_id_index");
            $table->index(['reference_type', 'reference_id'], "{$tableName}_reference_index");
            $table->index(['performed_by_type', 'performed_by_id'], "{$tableName}_performed_by_index");
            $table->unique(['wallet_id', 'idempotency_key'], "{$tableName}_idempotency_unique");
            $table->index('group_uuid', "{$tableName}_group_uuid_index");
            $table->unique('reverses_id', "{$tableName}_reverses_id_unique");

            $table->foreign('wallet_id')
                ->references('id')->on($walletsTable)
                ->restrictOnDelete();

            $table->foreign('reverses_id')
                ->references('id')->on($tableName)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('wallet.tables.transactions', 'wallet_transactions'));
    }
};
