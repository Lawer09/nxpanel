<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\ProjectAppInfoService;
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
            'adStatus'        => $this->ad_status,
            'appPlatform'     => $this->app_platform,
            'adspowerEnv'     => $this->adspower_env,
            'developerGmail'  => $this->developer_gmail,
            'appName'         => $this->app_name,
            'packageName'     => $this->package_name,
            'domainInfoStatus' => $this->domain_info_status,
            'admobPubId'      => $this->admob_pub_id,
            'domainUrl'       => $this->domain_url,
            'privacyPolicyUrl' => $this->privacy_policy_url,
            'termsUrl'        => $this->terms_url,
            'facebookInfoStatus' => $this->facebook_info_status,
            'facebookAppId'   => $this->facebook_app_id,
            'facebookAppToken' => $this->facebook_app_token,
            'facebookKeyHash' => $this->facebook_key_hash,
            'facebookClassName' => $this->facebook_class_name,
            'admobAccountStatus' => $this->admob_account_status,
            'admobAppId'      => $this->admob_app_id,
            'admobAdIds'      => $this->admob_ad_ids,
            'admobAppAdsTxt'  => $this->admob_app_ads_txt,
            'firebaseConfigNote' => $this->firebase_config_note,
            'yandexAccount'   => $this->yandex_account,
            'yandexAdIds'     => $this->yandex_ad_ids,
            'yandexAppAdsTxt' => $this->yandex_app_ads_txt,
            'storePageUrl'    => $this->store_page_url,
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
                    'appLink'    => $ua->app_link,
                    'enabled'    => $ua->enabled,
                    'remark'     => $ua->remark,
                    'createdAt'  => $ua->created_at,
                    'updatedAt'  => $ua->updated_at,
                ])
            ),
            'appInfos'        => $this->whenLoaded('appInfos', fn() =>
                $this->appInfos->map(fn($appInfo) => ProjectAppInfoService::format($appInfo))->values()
            ),
        ];
    }
}
