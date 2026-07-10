<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('overdue')) {
            Schema::create('overdue', function (Blueprint $table) {
                $table->id();
                $table->string('account_no', 50)->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->date('overdue_date')->index();
                $table->text('print_link')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
                
                $table->index(['account_no', 'overdue_date']);
                $table->index('created_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('overdue');
    }
};
