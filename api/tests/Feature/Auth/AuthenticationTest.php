<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    private function makeUser(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => 'jan@nexa.test',
            'phone' => '+50937000000',
            'password_hash' => bcrypt('bon-modpas-123'),
            'first_name' => 'Jan',
            'last_name' => 'Batis',
        ], $attributes));
    }

    #[Test]
    public function a_user_signs_in_with_their_email(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'bon-modpas-123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('error', null)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user', 'organizations']]);

        $this->assertSame(64, strlen($response->json('data.token')));
    }

    #[Test]
    public function a_user_signs_in_with_their_phone_number(): void
    {
        // Beaucoup de commerçants haïtiens n'ont pas d'adresse email.
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '+50937000000',
            'password' => 'bon-modpas-123',
        ])->assertCreated();
    }

    #[Test]
    public function the_plain_text_token_is_never_stored(): void
    {
        $this->makeUser();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'bon-modpas-123',
        ])->json('data.token');

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $token]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $token)]);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'move-modpas',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function an_unknown_identifier_gives_the_same_answer_as_a_wrong_password(): void
    {
        // Une réponse différente dirait à un attaquant quels comptes existent.
        $this->makeUser();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'pesonn@nexa.test',
            'password' => 'nenpòt',
        ]);
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'move-modpas',
        ]);

        $this->assertSame($unknown->status(), $wrongPassword->status());
        $this->assertSame($unknown->json('error.code'), $wrongPassword->json('error.code'));
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        $this->makeUser(['status' => 'SUSPENDED']);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'bon-modpas-123',
        ])->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    #[Test]
    public function repeated_failures_are_throttled_on_identifier_and_ip(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'jan@nexa.test',
                'password' => 'move-modpas',
            ])->assertStatus(422);
        }

        // Le bon mot de passe est refusé lui aussi : la limitation porte sur
        // la tentative, pas sur sa justesse.
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jan@nexa.test',
            'password' => 'bon-modpas-123',
        ])->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }

    #[Test]
    public function throttling_a_victim_from_one_ip_does_not_lock_them_out_elsewhere(): void
    {
        // Sinon la protection devient un déni de service : n'importe qui
        // verrouille le compte d'un commerçant en échouant cinq fois.
        $this->makeUser();

        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/api/v1/auth/login', [
                    'identifier' => 'jan@nexa.test',
                    'password' => 'move-modpas',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/auth/login', [
                'identifier' => 'jan@nexa.test',
                'password' => 'bon-modpas-123',
            ])->assertCreated();
    }

    #[Test]
    public function me_returns_the_exact_scope_of_the_session(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $delmas = $this->makeLocation($org, 'DELMAS');
        $this->makeLocation($org, 'PETIONVILLE');

        $membership = $this->makeMember($org, 'CASHIER', [$delmas], 'kesye@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('bon-modpas-123')])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'kesye@nexa.test',
            'password' => 'bon-modpas-123',
        ])->json('data.token');

        $response = $this->withToken($token)->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.role', 'CASHIER')
            ->assertJsonPath('data.has_full_location_access', false)
            ->assertJsonPath('data.organization.base_currency', 'HTG')
            ->assertJsonPath('data.location_ids', [$delmas->id]);

        $this->assertContains('order.create', $response->json('data.permissions'));
        $this->assertNotContains('order.refund', $response->json('data.permissions'));
    }

    #[Test]
    public function an_organization_the_user_does_not_belong_to_is_not_found(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');

        $membership = $this->makeMember($mine, 'OWNER', [], 'pwopriyete@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('bon-modpas-123')])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'pwopriyete@nexa.test',
            'password' => 'bon-modpas-123',
        ])->json('data.token');

        // 404 et non 403 : un 403 confirmerait que cette organisation existe.
        $this->withToken($token)
            ->withHeader('X-Organization', $theirs->id)
            ->getJson('/api/v1/me')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORGANIZATION_NOT_FOUND');
    }

    #[Test]
    public function a_revoked_token_stops_working_immediately(): void
    {
        $membership = $this->makeMember($this->makeOrganization('ti-machann'), 'OWNER', [], 'a@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('bon-modpas-123')])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'a@nexa.test',
            'password' => 'bon-modpas-123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $membership = $this->makeMember($this->makeOrganization('ti-machann'), 'OWNER', [], 'b@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('bon-modpas-123')])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'b@nexa.test',
            'password' => 'bon-modpas-123',
        ])->json('data.token');

        DB::table('personal_access_tokens')
            ->where('token', hash('sha256', $token))
            ->update(['expires_at' => now()->subMinute()]);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function a_request_without_a_token_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function every_response_carries_a_request_id(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-Id');
    }

    #[Test]
    public function the_readiness_probe_checks_its_dependencies(): void
    {
        $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('data.database', 'ok');
    }
}
