<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReceiptRegisterWorkspaceScopeTest extends TestCase
{
    public function test_front_office_receipts_are_clamped_to_the_resolved_till(): void
    {
        $request = Request::create('/api/pos/receipts/register', 'GET');
        $request->setUserResolver(fn () => $this->workspaceUser('front-office'));
        $request->attributes->set('resolved_till', (object) [
            'id' => 'till-a',
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
        ]);

        $this->assertSame([
            'branch_ids' => ['branch-a'],
            'till_id' => 'till-a',
        ], $this->resolveScope($request, 'company-a'));
    }

    public function test_front_office_cannot_request_a_different_branch_or_till(): void
    {
        foreach ([
            ['branch_id' => 'branch-b'],
            ['till_id' => 'till-b'],
        ] as $query) {
            $request = Request::create('/api/pos/receipts/register', 'GET', $query);
            $request->setUserResolver(fn () => $this->workspaceUser('front-office'));
            $request->attributes->set('resolved_till', (object) [
                'id' => 'till-a',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
            ]);

            try {
                $this->resolveScope($request, 'company-a');
                $this->fail('A Front Office request escaped its resolved till scope.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_front_office_rejects_a_resolved_till_from_another_company(): void
    {
        $request = Request::create('/api/pos/receipts/register', 'GET');
        $request->setUserResolver(fn () => $this->workspaceUser('front-office'));
        $request->attributes->set('resolved_till', (object) [
            'id' => 'till-a',
            'company_id' => 'company-b',
            'branch_id' => 'branch-a',
        ]);

        try {
            $this->resolveScope($request, 'company-a');
            $this->fail('A Front Office request accepted a till from another company.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_back_office_receipts_keep_user_branch_allocation_scope(): void
    {
        $request = Request::create('/api/pos/receipts/register', 'GET', [
            'branch_id' => 'branch-b',
        ]);
        $request->setUserResolver(fn () => new class
        {
            public string $user_type = 'cashier';
            public ?string $branch_id = null;

            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:back-office';
            }

            public function branches(): object
            {
                return new class
                {
                    public function where(): self
                    {
                        return $this;
                    }

                    public function pluck(): object
                    {
                        return collect(['branch-a', 'branch-b']);
                    }
                };
            }
        });

        $this->assertSame([
            'branch_ids' => ['branch-b'],
            'till_id' => null,
        ], $this->resolveScope($request, 'company-a'));
    }

    private function workspaceUser(string $workspace): object
    {
        return new class($workspace)
        {
            public function __construct(private readonly string $workspace) {}

            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:'.$this->workspace;
            }
        };
    }

    private function resolveScope(Request $request, string $companyId): array
    {
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SalesController::class, 'receiptAccessScope');
        $method->setAccessible(true);

        return $method->invoke($controller, $request, $companyId);
    }
}
