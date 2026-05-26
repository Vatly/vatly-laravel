<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatly_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('type');
            $table->string('plan_id');
            $table->string('vatly_id')->unique();
            $table->string('name');
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vatly_orders', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('vatly_id')->unique();
            $table->string('status');
            $table->integer('total');
            $table->integer('subtotal')->nullable();
            $table->json('tax_summary')->nullable();
            $table->string('currency');
            $table->string('invoice_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('customer_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('vatly_webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('vatly_id')->unique();
            $table->string('resource');
            $table->string('event_name');
            $table->string('entity_type');
            $table->string('entity_id')->index();
            $table->boolean('testmode');
            $table->timestamp('vatly_created_at');
            $table->string('vatly_customer_id')->nullable()->index();
            $table->json('object');
        });
    }
};
