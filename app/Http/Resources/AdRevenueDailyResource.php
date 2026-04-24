<?php

namespace App\Http\Resources;

use App\Models\AdRevenueDaily;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdRevenueDaily
 */
class AdRevenueDailyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reportDate'        => $this->report_date,
            'sourcePlatform'    => $this->source_platform,
            'accountId'         => $this->account_id,
            'projectId'         => $this->project_id,
            'providerAppId'     => $this->provider_app_id,
            'providerAdUnitId'  => $this->provider_ad_unit_id,
            'countryCode'       => $this->country_code,
            'devicePlatform'    => $this->device_platform,
            'adFormat'          => $this->ad_format,
            'reportType'        => $this->report_type,
            'adSourceCode'      => $this->ad_source_code,
            'adRequests'        => $this->ad_requests,
            'matchedRequests'   => $this->matched_requests,
            'impressions'       => $this->impressions,
            'clicks'            => $this->clicks,
            'estimatedEarnings' => $this->estimated_earnings,
            'ecpm'              => $this->ecpm,
            'ctr'               => $this->ctr,
            'matchRate'         => $this->match_rate,
            'showRate'          => $this->show_rate,
            'rawHeaderJson'     => $this->raw_header_json,
            'rawRowJson'        => $this->raw_row_json,
            'syncTime'          => $this->sync_time,
            'createdAt'         => $this->created_at,
            'updatedAt'         => $this->updated_at,
        ];
    }
}
