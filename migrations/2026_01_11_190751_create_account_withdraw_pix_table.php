<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

class CreateAccountWithdrawPixTable extends Migration
{
    public function up(): void
    {
        Schema::create('account_withdraw_pix', function (Blueprint $table) {
            $table->char('account_withdraw_id', 36)->primary(); // 1:1 com withdraw
            $table->string('type', 50);                         // email
            $table->string('key', 255);                         // email

            // Extra (auditoria)
            $table->timestamps(); // created_at / updated_at

            $table->foreign('account_withdraw_id')
                ->references('id')
                ->on('account_withdraw')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_withdraw_pix');
    }
}
