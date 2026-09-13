<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\ReviewResource;
use App\Jobs\ParseOrganization;
use App\Models\Organization;
use App\Services\Yandex\Parser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Работа с подключённой организацией.
 *
 * Контроллер намеренно остаётся тонким:
 *
 * - HTTP input/output;
 * - validation;
 * - выбор текущей организации;
 * - dispatch Job.
 *
 * Сам парсинг здесь отсутствует.
 */
final class OrganizationController extends Controller
{
    /**
     * Ключ текущей организации в session.
     */
    private const SESSION_KEY = 'current_organization_id';

    /**
     * Возвращает текущую организацию.
     */
    public function show(Request $request): OrganizationResource
    {
        return new OrganizationResource(
            $this->currentOrganization($request),
        );
    }

    /**
     * Сохраняет URL организации и запускает парсинг.
     */
    public function store(
        SaveOrganizationRequest $request,
        Parser $parser,
    ): OrganizationResource {
        $sourceUrl = $request->validated('url');

        /**
         * Проверяем, что URL действительно относится
         * к поддерживаемой организации Яндекс.Карт.
         */
        $url = $parser->resolve($sourceUrl);

        if ($url === null) {
            throw ValidationException::withMessages([
                'url' => [
                    'The URL is not a supported Yandex Maps organization link.',
                ],
            ]);
        }

        /**
         * В тестовом задании организация определяется
         * через business ID Яндекс.Карт.
         *
         * Если организация уже существует —
         * обновляем существующую запись.
         */
        $organization = Organization::query()->updateOrCreate(
            [
                'external_id' => $url->businessId,
            ],
            [
                'source_url' => $sourceUrl,
                'parse_status' => ParseStatus::Pending,
                'parse_progress' => 0,
                'parse_error' => null,
            ],
        );

        /**
         * Запоминаем именно эту организацию
         * как выбранную текущей session.
         *
         * Это важно, если ранее уже существовала
         * другая организация с большим ID.
         */
        $request->session()->put(
            self::SESSION_KEY,
            $organization->id,
        );

        /**
         * Запускаем фоновый парсинг.
         */
        ParseOrganization::dispatch(
            $organization->id,
        );

        return new OrganizationResource(
            $organization->fresh(),
        );
    }

    /**
     * Возвращает статус текущего парсинга.
     */
    public function status(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        return response()->json([
            'status' => $organization->parse_status->value,
            'progress' => $organization->parse_progress,
            'error' => $organization->parse_error,
            'parsed_at' => $organization->parsed_at?->toIso8601String(),
        ]);
    }

    /**
     * Возвращает отзывы текущей организации.
     *
     * Этот endpoint не обращается к Яндекс.Картам.
     *
     * Яндекс -> Job -> DB
     *
     * DB -> API -> Vue
     */
    public function reviews(
        Request $request,
    ): AnonymousResourceCollection {
        $organization = $this->currentOrganization($request);

        $reviews = $organization
            ->reviews()
            ->orderByDesc('reviewed_at')
            ->paginate(50);

        return ReviewResource::collection(
            $reviews,
        );
    }

    /**
     * Возвращает выбранную текущую организацию.
     *
     * ID хранится в session после последнего успешного
     * подключения через PUT /api/organization.
     *
     * Fallback на latest нужен для совместимости
     * с существующей БД, если session ещё не содержит ID.
     */
    private function currentOrganization(
        Request $request,
    ): Organization {
        $organizationId = $request->session()->get(
            self::SESSION_KEY,
        );

        if ($organizationId !== null) {
            $organization = Organization::query()
                ->find($organizationId);

            if ($organization !== null) {
                return $organization;
            }

            /**
             * Организация могла быть удалена.
             * Убираем устаревший ID из session.
             */
            $request->session()->forget(
                self::SESSION_KEY,
            );
        }

        return Organization::query()
            ->latest('id')
            ->firstOrFail();
    }
}
