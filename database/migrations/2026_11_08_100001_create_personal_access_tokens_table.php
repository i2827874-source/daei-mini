<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The personal_access_tokens table is created by the earlier migration.
    }

    public function down(): void
    {
        // This migration does not own the personal_access_tokens table.
    }
};
