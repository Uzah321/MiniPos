<?php

namespace Modules\Authentication\Providers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Authentication\Models\Brand;
use Modules\Authentication\Models\Complex;
use Modules\Authentication\Models\Country;
use Modules\Authentication\Models\PasswordPolicy;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Observers\SecurityAuditObserver;
use Modules\Terminals\Terminal;

class AuthenticationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Authentication';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'authentication';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        foreach ([
            Country::class, Brand::class, Complex::class, Store::class, Terminal::class,
            User::class, Role::class, Permission::class, PasswordPolicy::class,
            UserAccessAssignment::class,
        ] as $model) {
            $model::observe(SecurityAuditObserver::class);
        }
    }

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
