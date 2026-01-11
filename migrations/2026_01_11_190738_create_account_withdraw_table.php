<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

class CreateAccountWithdrawTable extends Migration
{
    public function up(): void
    {
        Schema::create('account_withdraw', function (Blueprint $table) {
            $table->char('id', 36)->primary();           // uuid
            $table->char('account_id', 36);

            $table->string('method', 50);                // PIX por enquanto
            $table->decimal('amount', 15, 2);

            $table->boolean('scheduled')->default(false);
            $table->dateTime('scheduled_for')->nullable();

            $table->boolean('done')->default(false);
            $table->boolean('error')->default(false);
            $table->string('error_reason', 255)->nullable();

            $table->boolean('processing')->default(false);
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['account_id', 'created_at'], 'idx_withdraw_account_created');
            $table->index(['scheduled', 'done', 'processing', 'scheduled_for'], 'idx_withdraw_cron');

            // FK
            $table->foreign('account_id')
                ->references('id')
                ->on('account')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_withdraw');
    }
}
