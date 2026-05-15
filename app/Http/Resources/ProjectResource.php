<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'projectCode'     => $this->project_code,
            'projectName'     => $this->project_name,
            'ownerName'       => $this->owner_name,
            'department'      => $this->department,
            'status'          => $this->status,
            'remark'          => $this->remark,
            'createdAt'       => $this->created_at,
            'updatedAt'       => $this->updated_at,
            'trafficAccounts' => $this->whenLoaded('trafficAccounts', fn() =>
                $this->trafficAccounts->map(fn($ta) => [
                    'id'                       => $ta->id,
                    'trafficPlatformAccountId' => $ta->traffic_platform_account_id,
                    'platformCode'             => $ta->platform_code,
                    'externalUid'              => $ta->external_uid,
                    'externalUsername'         => $ta->external_username,
                    'bindType'                 => $ta->bind_type,
                    'enabled'                  => $ta->enabled,
                    'remark'                   => $ta->remark,
                    'createdAt'                => $ta->created_at,
                    'updatedAt'                => $ta->updated_at,
                ])
            ),
            'adAccounts'      => $this->whenLoaded('adAccounts', fn() =>
                $this->adAccounts->map(fn($aa) => [
                    'id'                   => $aa->id,
                    'adPlatformAccountId'  => $aa->ad_platform_account_id,
                    'platformCode'         => $aa->platform_code,
                    'externalAppId'        => $aa->external_app_id,
                    'externalAdUnitId'     => $aa->external_ad_unit_id,
                    'bindType'             => $aa->bind_type,
                    'enabled'              => $aa->enabled,
                    'remark'               => $aa->remark,
                    'createdAt'            => $aa->created_at,
                    'updatedAt'            => $aa->updated_at,
                ])
            ),
            'userApps'        => $this->whenLoaded('userApps', fn() =>
                $this->userApps->map(fn($ua) => [
                    'id'         => $ua->id,
                    'appId'      => $ua->app_id,
                    'enabled'    => $ua->enabled,
                    'remark'     => $ua->remark,
                    'createdAt'  => $ua->created_at,
                    'updatedAt'  => $ua->updated_at,
                ])
            ),
        ];
    }
}
