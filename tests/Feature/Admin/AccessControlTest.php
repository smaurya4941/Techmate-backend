<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Page;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class AccessControlTest extends AdminTestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> URL => roles that may open it */
    public static function routeMatrix(): array
    {
        $all = User::ROLES;
        $sales = [User::ROLE_SUPER_ADMIN, User::ROLE_SALES_EXECUTIVE, User::ROLE_SALES_MANAGER];
        $catalogue = [User::ROLE_SUPER_ADMIN, User::ROLE_CATALOGUE_MANAGER];

        return [
            'products' => ['/admin/products', $catalogue],
            'products create' => ['/admin/products/create', $catalogue],
            'categories' => ['/admin/categories', $catalogue],
            'brands' => ['/admin/brands', $catalogue],
            'pages' => ['/admin/pages', $catalogue],
            'leads' => ['/admin/leads', $sales],
            'quotations' => ['/admin/quotations', $sales],
            'users' => ['/admin/users', [User::ROLE_SUPER_ADMIN]],
            'users create' => ['/admin/users/create', [User::ROLE_SUPER_ADMIN]],
            'settings' => ['/admin/manage-site-settings', [User::ROLE_SUPER_ADMIN]],
            'dashboard' => ['/admin', $all],
            'profile' => ['/admin/profile', $all],
        ];
    }

    #[DataProvider('routeMatrix')]
    public function test_each_role_reaches_only_its_own_modules(string $url, array $allowed): void
    {
        foreach (User::ROLES as $role) {
            $response = $this->actingAs($this->staff($role))->get($url);

            in_array($role, $allowed, true)
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    public function test_policy_matrix_for_destructive_and_privileged_abilities(): void
    {
        $admin = $this->superAdmin();
        $catalogue = $this->catalogueManager();
        $exec = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $manager = $this->staff(User::ROLE_SALES_MANAGER);

        $product = Product::factory()->create();
        $page = Page::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $exec->id]);

        // Catalogue managers edit but cannot delete; nobody can force-delete.
        $this->assertTrue($catalogue->can('update', $product));
        $this->assertFalse($catalogue->can('delete', $product));
        $this->assertTrue($admin->can('delete', $product));
        $this->assertFalse($admin->can('forceDelete', $product));
        $this->assertFalse($catalogue->can('delete', $page));
        $this->assertFalse($admin->can('forceDelete', $page));

        // Sales roles have no catalogue or CMS write access.
        foreach ([$exec, $manager] as $sales) {
            $this->assertFalse($sales->can('create', Product::class));
            $this->assertFalse($sales->can('update', $product));
            $this->assertFalse($sales->can('create', Page::class));
            $this->assertFalse($sales->can('viewAny', User::class));
        }

        // Leads are never deleted or created from the panel, by anyone.
        foreach ([$admin, $exec, $manager] as $user) {
            $this->assertFalse($user->can('delete', $lead));
            $this->assertFalse($user->can('create', Lead::class));
        }

        // Only roles holding the assign permission may assign.
        $this->assertTrue($manager->can('assign', $lead));
        $this->assertTrue($admin->can('assign', $lead));
        $this->assertFalse($exec->can('assign', $lead));
        $this->assertFalse($catalogue->can('view', $lead));

        // Staff accounts are deactivated, never deleted.
        $this->assertFalse($admin->can('delete', $exec));
    }

    public function test_category_with_products_or_children_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $withProducts = Category::factory()->create();
        Product::factory()->create(['category_id' => $withProducts->id]);
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $empty = Category::factory()->create();

        $this->assertFalse($admin->can('delete', $withProducts));
        $this->assertFalse($admin->can('delete', $parent));
        $this->assertTrue($admin->can('delete', $empty));
    }

    public function test_inactive_users_hold_no_permissions(): void
    {
        $inactive = $this->staff(User::ROLE_SUPER_ADMIN, ['is_active' => false]);

        $this->assertFalse($inactive->can('viewAny', User::class));
        $this->assertFalse($inactive->can('viewAny', Product::class));
        $this->assertFalse($inactive->can('viewAny', Brand::class));
        $this->assertFalse($inactive->can('viewAny', Quotation::class));
    }

    public function test_roles_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-secret-1A', 'role' => 'super_admin', 'is_active' => true]);

        $this->assertNull($user->role);
    }
}
