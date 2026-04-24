<?php

namespace App\Http\Resources;

use App\Models\AdPlatformAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdPlatformAccount
 */
class AdPlatformAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'sourcePlatform'   => $this->source_platform,
            'accountName'      => $this->account_name,
            'accountLabel'     => $this->account_label,
            'authType'         => $this->auth_type,
            'credentialsJson'  => $this->credentials_json,
            'status'           => $this->status,
            'tags'             => $this->tags,
            'assignedServerId' => $this->assigned_server_id,
            'backupServerId'   => $this->backup_server_id,
            'isolationGroup'   => $this->isolation_group,
            'reportingTimezone'=> $this->reporting_timezone,
            'currencyCode'     => $this->currency_code,
            'publisherId'      => $this->publisher_id,
            'createdAt'        => $this->created_at,
            'updatedAt'        => $this->updated_at,
        ];
    }
}
