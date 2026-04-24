<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CamelizeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        if ($data instanceof \Illuminate\Contracts\Support\Arrayable) {
            $data = $data->toArray();
        }
        $data = (array) $data;

        $out = [];
        foreach ($data as $key => $value) {
            $out[$this->snakeToCamel((string) $key)] = $value;
        }

        return $out;
    }

    private function snakeToCamel(string $key): string
    {
        if (str_contains($key, '_')) {
            $key = str_replace('_', ' ', $key);
            $key = ucwords($key);
            $key = str_replace(' ', '', $key);
            $key = lcfirst($key);
        }
        return $key;
    }
}
