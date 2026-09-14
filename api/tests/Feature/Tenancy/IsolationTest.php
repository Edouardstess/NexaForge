<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Location;
use App\Support\Tenancy\OrgContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * L'isolation est la garantie sur laquelle tout le reste repose. Si elle
 * cède, deux commerçants voient la caisse l'un de l'autre.               [D-03]
 */
final class IsolationTest extends TestCase
{
    #[Test]
    public function a_member_only_reads_their_own_organization(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');

        $this->makeLocation($mine, 'BTQ1');
        $this->makeLocation($mine, 'BTQ2');
        $this->makeLocation($theirs, 'AUTRE');

        $this->actingAsMember($this->makeMember($mine, 'OWNER'));

        $codes = Location::query()->pluck('code')->sort()->values()->all();

        $this->assertSame(['BTQ1', 'BTQ2'], $codes);
    }

    #[Test]
    public function a_location_of_another_organization_is_simply_not_found(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');
        $foreign = $this->makeLocation($theirs, 'AUTRE');

        $this->actingAsMember($this->makeMember($mine, 'OWNER'));

        // Introuvable, pas « interdit » : un 403 serait un oracle d'existence
        // permettant de sonder les identifiants des autres commerces.
        $this->assertNull(Location::query()->find($foreign->id));
    }

    #[Test]
    public function writing_into_another_organization_is_refused_even_when_asked_explicitly(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');

        $this->actingAsMember($this->makeMember($mine, 'OWNER'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Écriture refusée/');

        Location::query()->create([
            'organization_id' => $theirs->id,
            'code' => 'INTRUS',
            'name' => 'Injection',
        ]);
    }

    #[Test]
    public function a_new_row_is_stamped_with_the_active_organization(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $this->actingAsMember($this->makeMember($mine, 'OWNER'));

        $location = Location::query()->create(['code' => 'BTQ9', 'name' => 'Nouvo']);

        $this->assertSame($mine->id, $location->organization_id);
    }

    #[Test]
    public function an_empty_location_scope_means_every_location(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $this->makeLocation($org, 'BTQ1');
        $this->makeLocation($org, 'BTQ2');

        $this->actingAsMember($this->makeMember($org, 'OWNER'));

        $context = app(OrgContext::class);

        $this->assertTrue($context->hasFullLocationAccess());
        $this->assertSame(
            ['BTQ1', 'BTQ2'],
            Location::query()->visibleToCurrentUser('id')->pluck('code')->sort()->values()->all()
        );
    }

    #[Test]
    public function a_cashier_bound_to_one_shop_does_not_see_the_other(): void
    {
        // Le trou exact que les documents laissaient ouvert : le membership
        // était défini au niveau de l'organisation, la portée au niveau du
        // « tenant », et rien ne reliait les deux.
        $org = $this->makeOrganization('ti-machann');
        $delmas = $this->makeLocation($org, 'DELMAS');
        $petionville = $this->makeLocation($org, 'PETIONVILLE');

        $cashier = $this->makeMember($org, 'CASHIER', [$delmas]);
        $this->actingAsMember($cashier);

        $context = app(OrgContext::class);

        $this->assertFalse($context->hasFullLocationAccess());
        $this->assertTrue($context->canAccessLocation($delmas->id));
        $this->assertFalse($context->canAccessLocation($petionville->id));

        $visible = Location::query()->visibleToCurrentUser('id')->pluck('code')->all();

        $this->assertSame(['DELMAS'], $visible);
    }

    #[Test]
    public function permissions_come_from_the_role_template(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $this->actingAsMember($this->makeMember($org, 'CASHIER'));

        $context = app(OrgContext::class);

        $this->assertTrue($context->can('order.create'));
        $this->assertTrue($context->can('session.open'));
        $this->assertFalse($context->can('order.refund'), 'un caissier ne rembourse pas');
        $this->assertFalse($context->can('price.update'), 'un caissier ne change pas les prix');
        $this->assertFalse($context->can('user.manage'));
    }

    #[Test]
    public function an_explicit_grant_adds_a_permission_without_changing_the_role(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $cashier = $this->makeMember($org, 'CASHIER');
        $this->actingAsMember($cashier);

        $this->assertFalse(app(OrgContext::class)->can('order.refund'));

        DB::table('membership_permission_overrides')->insert([
            'membership_id' => $cashier->id,
            'permission_code' => 'order.refund',
            'effect' => 'GRANT',
        ]);
        $cashier->bumpPermissionsVersion();

        // Le cache est indexé par permissions_version : la nouvelle version
        // est un cache miss, donc le changement prend effet immédiatement.
        app(OrgContext::class)->forget();
        app(OrgContext::class)->bind($cashier->fresh(['organization']));

        $this->assertTrue(app(OrgContext::class)->can('order.refund'));
    }

    #[Test]
    public function an_explicit_revoke_removes_a_permission_the_role_grants(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $manager = $this->makeMember($org, 'MANAGER');
        $this->actingAsMember($manager);

        $this->assertTrue(app(OrgContext::class)->can('order.refund'));

        DB::table('membership_permission_overrides')->insert([
            'membership_id' => $manager->id,
            'permission_code' => 'order.refund',
            'effect' => 'REVOKE',
        ]);
        $manager->bumpPermissionsVersion();

        app(OrgContext::class)->forget();
        app(OrgContext::class)->bind($manager->fresh(['organization']));

        $this->assertFalse(app(OrgContext::class)->can('order.refund'));
    }

    #[Test]
    public function the_owner_role_holds_every_permission(): void
    {
        $org = $this->makeOrganization('ti-machann');
        $this->actingAsMember($this->makeMember($org, 'OWNER'));

        $declared = DB::table('permissions')->count();

        $this->assertSame($declared, count(app(OrgContext::class)->permissions()));
    }

    #[Test]
    public function platform_queries_can_opt_out_of_the_scope_deliberately(): void
    {
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');
        $this->makeLocation($mine, 'BTQ1');
        $this->makeLocation($theirs, 'AUTRE');

        $this->actingAsMember($this->makeMember($mine, 'OWNER'));

        // Explicite, nommé, greppable — jamais le comportement par défaut.
        $this->assertSame(2, Location::acrossAllOrganizations()->count());
        $this->assertSame(1, Location::query()->count());
    }

    #[Test]
    public function without_a_bound_context_nothing_is_scoped(): void
    {
        // Migrations, tâches planifiées, console : pas de contexte, pas de
        // filtre. Le filtre ne doit jamais dépendre d'un état implicite.
        $mine = $this->makeOrganization('ti-machann');
        $theirs = $this->makeOrganization('gwo-machann');
        $this->makeLocation($mine, 'BTQ1');
        $this->makeLocation($theirs, 'AUTRE');

        $this->assertFalse(app(OrgContext::class)->isBound());
        $this->assertSame(2, Location::query()->count());
    }
}
