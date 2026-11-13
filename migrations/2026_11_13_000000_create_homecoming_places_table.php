<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The town a member picked for their weather, when they would rather not use
 * (or have no) Member Map location. A town's centre from the geocoder, never a
 * point the member typed: two decimals is about a kilometre.
 */
return Migration::createTable('homecoming_places', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->decimal('lat', 7, 2);
    $table->decimal('lng', 7, 2);
    $table->string('label', 150);
    $table->timestamp('updated_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
