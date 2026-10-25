<?php

declare(strict_types=1);

namespace Odden\Sales;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Encryption\BusinessDataColumns;
use Odden\Core\Encryption\EncryptedColumns;
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
use Odden\Sales\Models\Quote;
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

        $this->callAfterResolving(DataAreas::class, function (DataAreas $areas): void {
            $areas->fields([
                'deals.lost_notes' => DataArea::Sales, 'deals.name' => DataArea::Sales, 'quotes.notes' => DataArea::Sales, 'quotes.internal_notes' => DataArea::Sales,
                'quotes.signed_by_name' => DataArea::Sales, 'quotes.signed_by_email' => DataArea::Sales,
                'meeting_bookings.invitee_name' => DataArea::Sales, 'meeting_bookings.invitee_email' => DataArea::Sales,
                'deals.lost_reason' => DataArea::Sales, 'quotes.title' => DataArea::Sales,
                'quote_items.name' => DataArea::Sales, 'quote_items.description' => DataArea::Sales,
                'deal_products.name' => DataArea::Sales, 'deal_products.description' => DataArea::Sales,
                'lead_routing_rules.criteria' => DataArea::Sales, 'stage_automations.action_payload' => DataArea::Sales,
            ]);
            $areas->tables(array_fill_keys([
                'odden_deals', 'odden_deal_products', 'odden_deal_stage_history', 'odden_pipelines', 'odden_pipeline_stages', 'odden_quotes', 'odden_quote_items',
                'odden_stage_automations', 'odden_sales_email_templates', 'odden_sales_lead_routing_rules', 'odden_sales_meeting_bookings',
                'odden_sales_meeting_links', 'odden_sales_playbooks', 'odden_sales_quotas', 'odden_sales_sequence_enrollments', 'odden_sales_sequences',
            ], DataArea::Sales));
        });

        $this->callAfterResolving(EncryptedColumns::class, function (EncryptedColumns $columns): void {
            $columns->add(fn (): string => config('odden-sales.tables.deals', 'odden_deals'), 'lost_notes', 'deals.lost_notes');
            $columns->add(fn (): string => config('odden-sales.tables.quotes', 'odden_quotes'), 'notes', 'quotes.notes');
            $columns->add(fn (): string => config('odden-sales.tables.quotes', 'odden_quotes'), 'internal_notes', 'quotes.internal_notes');
            $columns->add(fn (): string => config('odden-sales.tables.deals', 'odden_deals'), 'name', 'deals.name');
            $columns->addIndexed(Deal::class);
            $columns->add(fn (): string => config('odden-sales.tables.quotes', 'odden_quotes'), 'signed_by_name', 'quotes.signed_by_name');
            $columns->add(fn (): string => config('odden-sales.tables.quotes', 'odden_quotes'), 'signed_by_email', 'quotes.signed_by_email');
            $columns->add(fn (): string => config('odden-sales.tables.meeting_bookings', 'odden_sales_meeting_bookings'), 'invitee_name', 'meeting_bookings.invitee_name');
            $columns->add(fn (): string => config('odden-sales.tables.meeting_bookings', 'odden_sales_meeting_bookings'), 'invitee_email', 'meeting_bookings.invitee_email');
            $columns->add(fn (): string => config('odden-sales.tables.deals', 'odden_deals'), 'lost_reason', 'deals.lost_reason');
            $columns->add(fn (): string => config('odden-sales.tables.quotes', 'odden_quotes'), 'title', 'quotes.title');
            $columns->addIndexed(Quote::class);
            $columns->add(fn (): string => config('odden-sales.tables.quote_items', 'odden_quote_items'), 'name', 'quote_items.name');
            $columns->add(fn (): string => config('odden-sales.tables.quote_items', 'odden_quote_items'), 'description', 'quote_items.description');
            $columns->add(fn (): string => config('odden-sales.tables.products', 'odden_deal_products'), 'name', 'deal_products.name');
            $columns->add(fn (): string => config('odden-sales.tables.products', 'odden_deal_products'), 'description', 'deal_products.description');
            $columns->add(fn (): string => config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules'), 'criteria', 'lead_routing_rules.criteria');
            $columns->add(fn (): string => config('odden-sales.tables.automations', 'odden_stage_automations'), 'action_payload', 'stage_automations.action_payload');
        });

        // The figures a workspace can choose to keep encrypted (PLAN-encryption-at-rest.md, section 6).
        $this->callAfterResolving(BusinessDataColumns::class, function (BusinessDataColumns $columns): void {
            $columns->add(fn (): string => config('odden-sales.tables.deals', 'odden_deals'), 'amount', 'deals.amount');
            $columns->add(fn (): string => config('odden-sales.tables.quotas', 'odden_sales_quotas'), 'target_amount', 'sales_quotas.target_amount');
        });

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
