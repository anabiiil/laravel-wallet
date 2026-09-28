<?php

declare(strict_types=1);

use Anabil\Wallet\Support\ExactMatch;
use Anabil\Wallet\Support\MorphKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('wallet.tables.wallets', 'wallets'), function (Blueprint $table): void {
            $table->id();

            // Not nullable: every wallet belongs to exactly one holder.
            MorphKey::columns($table, 'holder', 'holder', false);

            ExactMatch::apply($table->string('name', 64), $this->getConnection());
            $table->char('currency', 3);
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('min_balance')->default(0);
            $table->string('status', 16)->default('active');
            $table->json('meta')->nullable();
            $table->timestamps();

            // Covers lookups by holder_type/holder_id as well as uniqueness,
            // so a separate morphs()-style index would be redundant.
            $table->unique(['holder_type', 'holder_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('wallet.tables.wallets', 'wallets'));
    }
};
