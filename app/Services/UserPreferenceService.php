<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;

class UserPreferenceService
{
    public const ALLOWED_KEYS = [
        'report.projectReport',
        'report.projectHourlyReport',
        'project-report-ratio-bar-colors',
    ];

    public static function allowedKeys(): array
    {
        return self::ALLOWED_KEYS;
    }

    public function list(User $user, ?array $keys = null): array
    {
        $query = UserPreference::query()
            ->where('user_id', $user->id)
            ->orderBy('preference_key');

        $allowedKeys = $keys ?: self::ALLOWED_KEYS;
        $query->whereIn('preference_key', $allowedKeys);

        return $query
            ->get()
            ->map(fn (UserPreference $preference) => $this->format($preference))
            ->values()
            ->all();
    }

    public function save(User $user, array $items): array
    {
        $saved = [];

        foreach ($items as $item) {
            $preferenceKey = (string) $item['preferenceKey'];
            $preferenceValue = $item['preferenceValue'];
            $valueHash = self::hashValue($preferenceValue);

            $preference = UserPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'preference_key' => $preferenceKey,
                ],
                [
                    'preference_value' => $preferenceValue,
                    'value_hash' => $valueHash,
                ],
            );

            $saved[] = $this->format($preference->refresh());
        }

        return $saved;
    }

    public static function hashValue(mixed $value): string
    {
        return hash('sha256', self::stableJsonEncode($value));
    }

    private static function stableJsonEncode(mixed $value): string
    {
        return json_encode(
            self::normalizeForStableJson($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: 'null';
    }

    private static function normalizeForStableJson(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            $normalized = new \stdClass();
            foreach ($properties as $key => $item) {
                $normalized->{$key} = self::normalizeForStableJson($item);
            }

            return $normalized;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => self::normalizeForStableJson($item), $value);
            }

            ksort($value);
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeForStableJson($item);
            }

            return $normalized;
        }

        return $value;
    }

    private function format(UserPreference $preference): array
    {
        return [
            'preferenceKey' => (string) $preference->preference_key,
            'preferenceValue' => $preference->preference_value,
            'valueHash' => (string) $preference->value_hash,
            'updatedAt' => $preference->updated_at,
        ];
    }
}
