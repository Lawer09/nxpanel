<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TicketCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_multiple_open_tickets(): void
    {
        $user = $this->actingTicketUser();
        Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Existing ticket',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
        ]);

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => 'Second ticket',
            'level' => 2,
            'message' => 'Need help again',
            'personal_email' => 'personal@example.com',
        ])->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(2, Ticket::where('user_id', $user->id)->where('status', Ticket::STATUS_OPENING)->count());
        $this->assertDatabaseHas('v2_ticket', [
            'user_id' => $user->id,
            'subject' => 'Second ticket',
            'personal_email' => 'personal@example.com',
        ]);
        $this->assertDatabaseHas('v2_ticket_message', [
            'user_id' => $user->id,
            'message' => 'Need help again',
        ]);
    }

    public function test_personal_email_is_optional(): void
    {
        $user = $this->actingTicketUser('optional-ticket@example.com');

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => 'No personal email',
            'level' => 1,
            'message' => 'No optional email',
        ])->assertOk()
            ->assertJsonPath('status', 'success');

        $ticket = Ticket::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($ticket->personal_email);
    }

    public function test_invalid_personal_email_is_rejected(): void
    {
        $user = $this->actingTicketUser('invalid-ticket@example.com');

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => 'Invalid email',
            'level' => 1,
            'message' => 'Email should fail validation',
            'personal_email' => 'not-an-email',
        ])->assertStatus(422);

        $this->assertSame(0, Ticket::where('user_id', $user->id)->count());
    }

    public function test_ticket_fetch_returns_personal_email(): void
    {
        $user = $this->actingTicketUser('fetch-ticket@example.com');
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Fetch ticket',
            'level' => 0,
            'personal_email' => 'fetch-personal@example.com',
        ]);

        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)
            ->assertOk()
            ->assertJsonPath('data.personal_email', 'fetch-personal@example.com');
    }

    private function actingTicketUser(string $email = 'ticket-user@example.com'): User
    {
        $this->withoutMiddleware();

        $user = User::create([
            'email' => $email,
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'expired_at' => 0,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ]);

        Auth::setUser($user);

        return $user;
    }
}
