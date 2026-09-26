<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the wallet holder fixtures (User, Merchant, Admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'merchants', 'admins'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'merchants', 'admins'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
