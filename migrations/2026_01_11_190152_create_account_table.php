<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

class CreateAccountTable extends Migration
{
    public function up(): void
    {
        Schema::create('account', function (Blueprint $table) {
            $table->char('id', 36)->primary();  
            $table->string('name', 255);
            $table->decimal('balance', 15, 2)->default(0);

            $table->timestamps(); // created_at / updated_at 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account');
    }
}
