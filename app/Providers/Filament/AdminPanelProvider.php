<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\NavigationGroup;
use App\Filament\Support\Branding;
use App\Support\Help\HelpTopics;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->profile(isSimple: false)
            ->brandName(fn (): string => Branding::name())
            ->brandLogo(fn () => Branding::brand())
            ->brandLogoHeight('2.4rem')
            ->favicon(fn () => Branding::logo())
            ->colors(fn () => Branding::colors())
            ->font('Cairo')
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->navigationGroups(NavigationGroup::class)
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.admin.pos-button')->render())
            // the fiscal year shown everywhere, and a reminder while an older one is open
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => view('filament.admin.fiscal-year.switcher')->render())
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => view('filament.admin.fiscal-year.banner')->render())
            // "?" on every page that has a help file in resources/help/
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, function (array $scopes): string {
                $topic = isset($scopes[0]) ? HelpTopics::forPage($scopes[0]) : null;

                return $topic === null ? '' : view('filament.admin.help.button', ['topic' => $topic])->render();
            })
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
