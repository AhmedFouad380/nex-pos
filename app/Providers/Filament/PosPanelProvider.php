<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pos\Pages\PointOfSale;
use App\Filament\Support\Branding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PosPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('pos')
            ->path('pos')
            ->login(Login::class)
            ->profile(isSimple: false)
            ->brandName(Branding::SYSTEM_NAME)
            ->brandLogo(fn () => Branding::brand())
            ->darkModeBrandLogo(fn () => Branding::brand(dark: true))
            ->brandLogoHeight('2.4rem')
            ->favicon(fn () => Branding::icon())
            ->colors(fn () => Branding::colors())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.pos.admin-button')->render())
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => view('filament.copyright')->render())
            ->font('Cairo')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Pos/Resources'), for: 'App\Filament\Pos\Resources')
            ->discoverPages(in: app_path('Filament/Pos/Pages'), for: 'App\Filament\Pos\Pages')
            ->pages([PointOfSale::class])
            ->homeUrl(fn () => PointOfSale::getUrl())
            ->discoverWidgets(in: app_path('Filament/Pos/Widgets'), for: 'App\Filament\Pos\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
