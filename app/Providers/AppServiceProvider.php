<?php

namespace App\Providers;

use App\Domain\Finance\Services\SystemAccounts;
use App\Filament\Support\UiDefaults;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Audit\AuthActivity;
use App\Support\MorphMap;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // remembers system account ids for one request / job only
        $this->app->scoped(SystemAccounts::class);
    }

    public function boot(): void
    {
        // Store short aliases (e.g. "sale_item") in *_type columns instead of class names.
        Relation::enforceMorphMap(MorphMap::map());

        // Catch N+1 queries and typos in attribute names during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // سجل النشاط: who logged in / out, and failed attempts
        Event::subscribe(AuthActivity::class);

        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        $this->applyBusinessSettings();
        UiDefaults::register();
    }

    /** Time zone and date format chosen in the business settings (skipped before the settings table exists). */
    private function applyBusinessSettings(): void
    {
        $values = rescue(function () {
            $settings = app(GeneralSettings::class);

            return [$settings->timezone, $settings->date_format];
        }, null, false);
        if ($values === null) {
            return;
        }
        [$timezone, $date] = $values;

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        Table::configureUsing(fn (Table $table) => $table->defaultDateDisplayFormat($date)->defaultDateTimeDisplayFormat($date.' H:i'));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultDateDisplayFormat($date)->defaultDateTimeDisplayFormat($date.' H:i'));
    }
}
