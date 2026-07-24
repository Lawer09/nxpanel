<?php

namespace Tests\Feature;

use App\Models\AppClient;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationProjectStatusFieldsTest extends TestCase
{
    use RefreshDatabase;

    private AppClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = AppClient::create([
            'name' => 'Project Status Client',
            'app_id' => 'project-status-app',
            'app_token' => 'project-status-token',
            'app_secret' => 'project-status-secret',
            'is_enabled' => true,
        ]);
    }

    /**
     * Verify application clients can update project status-related fields by project code.
     */
    public function test_application_can_update_project_status_fields_by_project_code(): void
    {
        Project::create([
            'project_code' => 'APP_STATUS_001',
            'project_name' => 'Application Status Project',
            'status' => Project::STATUS_ACTIVE,
            'ad_status' => Project::AD_STATUS_NOT_LAUNCHED,
            'domain_info_status' => 'pending',
            'facebook_info_status' => 'pending',
            'admob_account_status' => 'pending',
            'facebook_app_token' => 'facebook-token-placeholder',
        ]);

        $response = $this->postJson($this->endpoint(), [
            'projectCode' => 'APP_STATUS_001',
            'status' => Project::STATUS_INACTIVE,
            'adStatus' => Project::AD_STATUS_WHITE_PACKAGE_ONLINE,
            'domainInfoStatus' => 'completed',
            'facebookInfoStatus' => 'completed',
            'admobAccountStatus' => null,
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.projectCode', 'APP_STATUS_001')
            ->assertJsonPath('data.status', Project::STATUS_INACTIVE)
            ->assertJsonPath('data.adStatus', Project::AD_STATUS_WHITE_PACKAGE_ONLINE)
            ->assertJsonPath('data.domainInfoStatus', 'completed')
            ->assertJsonPath('data.facebookInfoStatus', 'completed')
            ->assertJsonPath('data.admobAccountStatus', null)
            ->assertJsonMissingPath('data.facebookAppToken');

        $this->assertDatabaseHas('project_projects', [
            'project_code' => 'APP_STATUS_001',
            'status' => Project::STATUS_INACTIVE,
            'ad_status' => Project::AD_STATUS_WHITE_PACKAGE_ONLINE,
            'domain_info_status' => 'completed',
            'facebook_info_status' => 'completed',
            'admob_account_status' => null,
            'facebook_app_token' => 'facebook-token-placeholder',
        ]);
    }

    /**
     * Verify at least one status field must be present.
     */
    public function test_application_status_update_requires_status_field(): void
    {
        Project::create([
            'project_code' => 'APP_STATUS_002',
            'project_name' => 'Application Status Project',
            'status' => Project::STATUS_ACTIVE,
        ]);

        $this->postJson($this->endpoint(), [
            'projectCode' => 'APP_STATUS_002',
        ], $this->headers())->assertStatus(422);
    }

    private function endpoint(): string
    {
        return '/api/v3/application/projects/update-status-fields';
    }

    private function headers(): array
    {
        return [
            'X-App-Id' => $this->client->app_id,
            'X-App-Token' => $this->client->app_token,
        ];
    }
}
