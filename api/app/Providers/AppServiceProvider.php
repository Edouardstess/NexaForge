<?php

namespace App\Providers;

use App\Support\Tenancy\OrgContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Le contexte d'organisation vit pour la durée d'une requête. [D-03]
        $this->app->singleton(OrgContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Un attribut absent doit lever, pas renvoyer null en silence : c'est
        // ce qui transforme un eager-load incomplet en bug invisible.
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::shouldBeStrict(! $this->app->isProduction());

        // Toute relation polymorphe passe par une carte explicite : le nom de
        // classe PHP ne doit jamais fuiter en base. Chaque modèle morphable
        // s'enregistre ici au moment où il est ajouté.
        Relation::enforceMorphMap([]);

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
