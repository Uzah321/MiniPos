<?php

use App\Http\Middleware\AuthenticateSyncNode;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\RequireBusinessMode;
use App\Http\Middleware\RequireCompanyPermission;
use App\Http\Middleware\RequirePlatformAdministrator;
use App\Http\Middleware\RequireWorkspaceAbility;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => Authenticate::class,
            'resolve.company' => ResolveCompanyFromDomain::class,
            'check.license' => CheckCompanyLicense::class,
            'resolve.till' => ResolveTillFromToken::class,
            'company.permission' => RequireCompanyPermission::class,
            'business.mode' => RequireBusinessMode::class,
            'platform.admin' => RequirePlatformAdministrator::class,
            'sync.node' => AuthenticateSyncNode::class,
            'workspace' => RequireWorkspaceAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
