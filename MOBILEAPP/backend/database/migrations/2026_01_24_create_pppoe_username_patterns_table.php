<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pppoe_username_patterns')) {
            Schema::create('pppoe_username_patterns', function (Blueprint $table) {
                $table->id();
                $table->string('pattern_name');
                $table->enum('pattern_type', ['username', 'password']);
                $table->json('sequence');
                $table->string('created_by', 255)->default('system');
                $table->string('updated_by', 255)->default('system');
                $table->timestamps();
                
                $table->unique('pattern_type');
            });

            DB::table('pppoe_username_patterns')->insert([
                [
                    'pattern_name' => 'Default Username Pattern',
                    'pattern_type' => 'username',
                    'sequence' => json_encode([
                        ['id' => '1769222697092', 'type' => 'first_name', 'label' => 'First Name'],
                        ['id' => '1769222697780', 'type' => 'first_name_initial', 'label' => 'First Name Initial']
                    ]),
                    'created_by' => 'system',
                    'updated_by' => 'system',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'pattern_name' => 'Default Password Pattern',
                    'pattern_type' => 'password',
                    'sequence' => json_encode([
                        ['id' => '1769222697093', 'type' => 'last_name', 'label' => 'Last Name'],
                        ['id' => '1769222697094', 'type' => 'mobile_number_last_4', 'label' => 'Mobile Number (Last 4)']
                    ]),
                    'created_by' => 'system',
                    'updated_by' => 'system',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_username_patterns');
    }
};
