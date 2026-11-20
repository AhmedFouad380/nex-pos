<?php

use App\Domain\Sales\Enums\SaleStatus;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

dataset('models', fn () => collect(glob(dirname(__DIR__, 3).'/app/Domain/*/Models/*.php'))
    ->map(fn ($f) => 'App\\Domain\\'.basename(dirname($f, 2)).'\\Models\\'.basename($f, '.php'))
    ->values()->all());

it('maps every model to an existing table whose columns cover $fillable', function (string $class) {
    $model = new $class;
    expect(Schema::hasTable($model->getTable()))->toBeTrue();

    $missing = array_diff($model->getFillable(), Schema::getColumnListing($model->getTable()));
    expect($missing)->toBeEmpty();
})->with('models');

it('resolves every relation to a valid query', function (string $class) {
    $model = new $class;
    expect($model)->toBeInstanceOf(Model::class);

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class !== $class || $method->getNumberOfParameters() > 0
            || ! str_contains((string) $method->getReturnType(), 'Relations')) {
            continue;
        }
        $relation = $model->{$method->name}();
        expect($relation)->toBeInstanceOf(Relation::class);
        if (! $relation instanceof MorphTo) {
            $relation->getQuery()->limit(1)->get();
        }
    }
})->with('models');

it('registers every domain model in the morph map', function (string $class) {
    expect(MorphMap::map())->toContain($class);
})->with('models');

it('translates enum labels', function () {
    app()->setLocale('ar');
    expect(SaleStatus::Final->getLabel())->toBe('نهائية');

    app()->setLocale('en');
    expect(SaleStatus::Final->getLabel())->toBe('Final');
});
