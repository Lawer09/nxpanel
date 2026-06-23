<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthParameterCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoint_still_accepts_authorization_header(): void
    {
        $user = $this->createUser();
        $authData = $this->authData($user);

        $this->getJson('/api/v3/user/getSubscribe', [
            'Authorization' => $authData,
        ])->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_user_endpoint_accepts_auth_data_query_parameter(): void
    {
        $user = $this->createUser();
        $authData = urlencode($this->authData($user));

        $this->getJson('/api/v3/user/getSubscribe?auth_data=' . $authData)
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_user_endpoint_accepts_raw_token_query_parameter(): void
    {
        $user = $this->createUser();
        $rawToken = urlencode(str_replace('Bearer ', '', $this->authData($user)));

        $this->getJson('/api/v3/user/getSubscribe?auth_data=' . $rawToken)
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_invite_endpoints_accept_auth_data_parameter(): void
    {
        $inviter = $this->createUser('inviter@example.com');
        $invitee = $this->createUser('invitee@example.com');
        $inviterAuth = $this->authData($inviter);
        $inviteeAuth = $this->authData($invitee);

        $createResponse = $this->postJson('/api/v3/user/invite-codes/create', [
            'auth_data' => $inviterAuth,
        ])->assertOk();

        $inviteCode = $createResponse->json('data.0.code');
        $this->assertNotEmpty($inviteCode);

        $this->postJson('/api/v3/user/invite-codes/use', [
            'auth_data' => $inviteeAuth,
            'inviteCode' => $inviteCode,
        ])->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.inviterUserId', $inviter->id);

        $this->getJson('/api/v3/user/invite/summary?auth_data=' . urlencode($inviterAuth))
            ->assertOk()
            ->assertJsonPath('data.invitedUsers', 1);
    }

    public function test_missing_or_invalid_auth_data_is_forbidden(): void
    {
        $this->getJson('/api/v3/user/getSubscribe')
            ->assertStatus(403);

        $this->getJson('/api/v3/user/getSubscribe?auth_data=invalid-token')
            ->assertStatus(403);
    }

    public function test_client_subscription_json_still_uses_token_parameter(): void
    {
        $user = $this->createUser();

        $response = $this->getJson('/api/v3/client/sub/json?token=' . urlencode($user->token))
            ->assertOk();

        $groups = $response->json('data');
        if (!empty($groups)) {
            $firstGroup = collect($groups)->first();
            $firstNode = is_array($firstGroup) ? ($firstGroup[0] ?? null) : null;

            if (is_array($firstNode)) {
                $this->assertArrayHasKey('country_code', $firstNode);
                $this->assertArrayHasKey('country_name', $firstNode);
            }
        }
    }

    public function test_client_subscription_json_ignores_banned_status(): void
    {
        $user = $this->createUser('banned-subscription-user@example.com');
        $user->forceFill(['banned' => 1])->save();

        $this->getJson('/api/v3/client/sub/json?token=' . urlencode($user->token))
            ->assertOk();
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'expired_at' => time() + 86400,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ]);
    }

    private function authData(User $user): string
    {
        return (new AuthService($user))->generateAuthData()['auth_data'];
    }
}
