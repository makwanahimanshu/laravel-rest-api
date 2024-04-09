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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('name', 255)->nullable();
            $table->string('designation', 255)->nullable();
            $table->string('phone_number', 255)->nullable();
            $table->string('profile_img', 255)->nullable();
            $table->date('birthdate')->nullable();
            $table->bigInteger('staff_created_by')->nullable();
            $table->tinyInteger('is_staff_user')->default(0)->comment('0 = Inactive, 1 = Active');
            $table->tinyInteger('is_profile_filled')->default(0)->comment('0 = Inactive, 1 = Active');
            $table->unsignedBigInteger('user_type_id');
            $table->foreign('user_type_id')->references('id')->on('user_type');
            $table->unsignedBigInteger('user_status_id');
            $table->foreign('user_status_id')->references('id')->on('user_status');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Insert a single record
        DB::table('users')->insert([
            'email' => 'admin@yopmail.com',
            'email_verified_at' => now()->format('Y-m-d H:i:s'),
            'password' => bcrypt('admin@123'),
            'name' => 'Admin',
            'is_profile_filled' => 1,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
