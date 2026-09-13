<?php

use App\Enums\ParseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            /**
             * ID организации на стороне Яндекс.Карт.
             *
             * Храним string, а не unsignedBigInteger:
             * внешний идентификатор нам не принадлежит,
             * и нет смысла привязываться к его формату.
             */
            $table->string('external_id')->unique();

            /**
             * URL, который передал пользователь.
             *
             * Сохраняем именно исходную ссылку,
             * например:
             *
             * https://yandex.ru/maps/-/CTh9mLkf
             */
            $table->text('source_url');

            $table->string('name')->nullable();

            /**
             * DECIMAL вместо float.
             *
             * Например:
             * 4.90
             */
            $table->decimal('rating', 3, 2)->nullable();

            /**
             * Все оценки.
             */
            $table->unsignedInteger('ratings_count')->nullable();

            /**
             * Текстовые отзывы.
             */
            $table->unsignedInteger('reviews_count')->nullable();

            /**
             * Текущее состояние фонового парсинга.
             */
            $table->string('parse_status')
                ->default(ParseStatus::Pending->value);

            /**
             * Прогресс от 0 до 100.
             */
            $table->unsignedTinyInteger('parse_progress')
                ->default(0);

            /**
             * Ошибка последней попытки.
             */
            $table->text('parse_error')->nullable();

            /**
             * Когда последний полный парсинг
             * успешно завершился.
             */
            $table->timestamp('parsed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
