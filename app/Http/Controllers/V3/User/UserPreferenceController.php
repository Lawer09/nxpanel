<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserPreferencesFetchRequest;
use App\Http\Requests\User\UserPreferencesSaveRequest;
use App\Services\UserPreferenceService;
use Illuminate\Http\JsonResponse;

class UserPreferenceController extends Controller
{
    public function index(
        UserPreferencesFetchRequest $request,
        UserPreferenceService $service,
    ): JsonResponse {
        $keys = $request->validated()['keys'] ?? null;

        return $this->ok($service->list($request->user(), $keys));
    }

    public function save(
        UserPreferencesSaveRequest $request,
        UserPreferenceService $service,
    ): JsonResponse {
        $items = $this->resolveItemsWithRawJsonValues($request);

        return $this->ok($service->save($request->user(), $items));
    }

    private function resolveItemsWithRawJsonValues(UserPreferencesSaveRequest $request): array
    {
        $validatedItems = $request->validated()['items'];
        $rawPayload = json_decode($request->getContent(), false);
        $rawItems = is_object($rawPayload) && is_array($rawPayload->items ?? null)
            ? $rawPayload->items
            : [];

        $items = [];
        foreach ($validatedItems as $index => $item) {
            $rawItem = $rawItems[$index] ?? null;

            $items[] = [
                'preferenceKey' => $item['preferenceKey'],
                'preferenceValue' => is_object($rawItem) && property_exists($rawItem, 'preferenceValue')
                    ? $rawItem->preferenceValue
                    : $item['preferenceValue'],
            ];
        }

        return $items;
    }
}
