<?php

namespace Tests\Unit;

use App\Services\Cashups\CashupBranchAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CashupBranchAccessServiceTest extends TestCase
{
    public function test_non_superadmin_receives_all_assigned_branches_when_no_filter_is_selected(): void
    {
        $request = $this->requestFor($this->ordinaryUser(['branch-a', 'branch-b'], 'branch-a'));

        $this->assertSame(
            ['branch-a', 'branch-b'],
            (new CashupBranchAccessService())->resolve($request, 'company-1')
        );
    }

    public function test_non_superadmin_can_filter_to_an_assigned_branch(): void
    {
        $request = $this->requestFor(
            $this->ordinaryUser(['branch-a', 'branch-b'], 'branch-a'),
            ['branch_id' => 'branch-b']
        );

        $this->assertSame(
            ['branch-b'],
            (new CashupBranchAccessService())->resolve($request, 'company-1')
        );
    }

    public function test_non_superadmin_cannot_filter_to_an_unassigned_branch(): void
    {
        $request = $this->requestFor(
            $this->ordinaryUser(['branch-a'], 'branch-a'),
            ['branch_id' => 'branch-z']
        );

        try {
            (new CashupBranchAccessService())->resolve($request, 'company-1');
            $this->fail('An unassigned branch filter must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('You are not allocated to the selected branch.', $exception->getMessage());
        }
    }

    public function test_superadmin_without_a_branch_filter_is_company_wide(): void
    {
        $user = $this->ordinaryUser([], null);
        $user->user_type = 'super_admin';
        $request = $this->requestFor($user);

        $this->assertSame([], (new CashupBranchAccessService())->resolve($request, 'company-1'));
    }

    private function requestFor(object $user, array $query = []): Request
    {
        $request = Request::create('/cashup-report', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function ordinaryUser(array $branchIds, ?string $primaryBranchId): object
    {
        return new class($branchIds, $primaryBranchId) {
            public string $user_type = 'cashier';
            public ?string $branch_id;
            public object $role;

            public function __construct(private array $branchIds, ?string $primaryBranchId)
            {
                $this->branch_id = $primaryBranchId;
                $this->role = (object) ['name' => 'Cashier'];
            }

            public function branches(): object
            {
                return new class($this->branchIds) {
                    public function __construct(private array $branchIds)
                    {
                    }

                    public function where(): self
                    {
                        return $this;
                    }

                    public function pluck(): Collection
                    {
                        return collect($this->branchIds);
                    }
                };
            }
        };
    }
}
