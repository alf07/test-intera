<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            /**
             * Какая организация владеет отзывом.
             */
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            /**
             * ID отзыва в Яндексе.
             *
             * Пара:
             *
             * organization_id + external_id
             *
             * уникальна.
             */
            $table->string('external_id');

            $table->string('author')->nullable();

            /**
             * Оценка от 1 до 5.
             */
            $table->unsignedTinyInteger('rating')->nullable();

            /**
             * Дата отзыва.
             */
            $table->timestamp('reviewed_at')->nullable();

            $table->text('text')->nullable();

            $table->timestamps();

            /**
             * Главная защита от дублей.
             */
            $table->unique([
                'organization_id',
                'external_id',
            ]);

            $table->index([
                'organization_id',
                'reviewed_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
