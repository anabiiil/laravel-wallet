<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the wallet holder fixtures (User, Merchant, Admin) and the
 * Order fixture used as a transaction reference.
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

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');

        foreach (['users', 'merchants', 'admins'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
