<?php

declare(strict_types=1);

namespace App\Enums;

enum ParseStatus: string
{
    /**
     * Парсинг ещё не запускался.
     */
    case Pending = 'pending';

    /**
     * Парсинг выполняется.
     */
    case Processing = 'processing';

    /**
     * Успешно.
     */
    case Ok = 'ok';

    /**
     * Яндекс временно недоступен.
     */
    case Unavailable = 'unavailable';

    /**
     * Яндекс ограничил доступ.
     */
    case Blocked = 'blocked';

    /**
     * CAPTCHA.
     */
    case Captcha = 'captcha';

    /**
     * Изменилась структура ответа Яндекса.
     */
    case MarkupChanged = 'markup_changed';

    /**
     * Пустой ответ.
     */
    case Empty = 'empty';

    /**
     * Неизвестная ошибка.
     */
    case Failed = 'failed';
}
