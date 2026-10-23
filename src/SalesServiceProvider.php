<?php

declare(strict_types=1);

namespace Odden\Sales;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Support\ModelRegistry;
use Odden\Sales\Console\Commands\ExpireStaleQuotesCommand;
use Odden\Sales\Console\Commands\ProcessCadencesCommand;
use Odden\Sales\Demo\SalesDemoSeeder;
use Odden\Sales\Listeners\MoveMergedRecords;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesSequenceEnrollment;
use Odden\Sales\Registry\MoveDealStage;
use Odden\Sales\Registry\SearchDeals;
use Odden\Sales\Services\EloquentDealGateway;

class SalesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/odden-sales.php',
            'odden-sales'
        );

        $this->app->bind(DealGateway::class, EloquentDealGateway::class);

        $this->callAfterResolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            $registry->discover(__DIR__.'/Models', 'Odden\\Sales\\Models');
        });

        $this->callAfterResolving(ActionRegistry::class, fn (ActionRegistry $registry) => $registry->register(SearchDeals::class, MoveDealStage::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->callAfterResolving(DemoRegistry::class, fn (DemoRegistry $registry) => $registry->register(SalesDemoSeeder::class));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-sales');
        if (config('odden-sales.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (class_exists(Contact::class)) {
            Contact::resolveRelationUsing('deals', function (Contact $contact) {
                return $contact->belongsToMany(
                    Deal::class,
                    config('odden-core.tables.associations', 'odden_associations'),
                    'child_id',
                    'parent_id'
                )
                    ->wherePivot('child_type', $contact->getMorphClass())
                    ->wherePivot('parent_type', (new Deal)->getMorphClass())
                    ->withPivot(['id', 'type'])
                    ->withTimestamps();
            });

            Contact::resolveRelationUsing('salesSequenceEnrollments', function (Contact $contact) {
                return $contact->hasMany(SalesSequenceEnrollment::class, 'contact_id')->orderBy('created_at', 'desc');
            });
        }

        if (class_exists(Company::class)) {
            Company::resolveRelationUsing('deals', function (Company $company) {
                return $company->belongsToMany(
                    Deal::class,
                    config('odden-core.tables.associations', 'odden_associations'),
                    'child_id',
                    'parent_id'
                )
                    ->wherePivot('child_type', $company->getMorphClass())
                    ->wherePivot('parent_type', (new Deal)->getMorphClass())
                    ->withPivot(['id', 'type'])
                    ->withTimestamps();
            });
        }

        // Synchronous on purpose: runs inside Core's merge transaction.
        Event::listen(ContactsMerged::class, [MoveMergedRecords::class, 'handleContactsMerged']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessCadencesCommand::class,
                ExpireStaleQuotesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/odden-sales.php' => config_path('odden-sales.php'),
            ], 'odden-sales-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'odden-sales-migrations');
        }
    }
}
