<?php

namespace App\Http\Resources;

use App\Models\ProjectPlatformAppMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectPlatformAppMap
 */
class ProjectPlatformAppMapResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'projectId'     => $this->project_id,
            'sourcePlatform'=> $this->source_platform,
            'accountId'     => $this->account_id,
            'providerAppId' => $this->provider_app_id,
            'status'        => $this->status,
            'account'       => $this->whenLoaded('account', function () {
                return [
                    'id'            => $this->account->id,
                    'accountName'   => $this->account->account_name,
                    'sourcePlatform'=> $this->account->source_platform,
                ];
            }),
            'createdAt'     => $this->created_at,
            'updatedAt'     => $this->updated_at,
        ];
    }
}
