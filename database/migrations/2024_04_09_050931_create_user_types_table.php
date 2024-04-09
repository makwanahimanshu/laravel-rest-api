<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_types', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->tinyInteger('status')->default(1)->comment('0 = Inactive, 1 = Active');
            $table->unsignedBigInteger('created_by')->comment('userid');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unsignedBigInteger('updated_by')->comment('userid');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->unsignedBigInteger('deleted_by')->comment('userid')->nullable()->default(null);;
            $table->foreign('deleted_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();
        });

        // Insert User Type table static record
        DB::table('user_types')->insert([
            [
                'title' => 'Admin',
                'status' => 1,
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ],
            [
                'title' => 'Project Manager',
                'status' => 1,
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ],
            [
                'title' => 'Staff',
                'status' => 1,
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_types');
    }
};
