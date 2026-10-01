<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards against a model pointing at a table that does not exist.
 *
 * Eloquent pluralises the class name by default, so a table declared in singular
 * form (pricing, payout, payment) resolves to a name that was never created and
 * every query through that relation throws. This failed silently until an
 * endpoint was exercised on MySQL.
 */
class ModelTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_resolves_to_an_existing_table(): void
    {
        $mismatches = [];
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new ('App\\Models\\' . basename($file, '.php'))();

            $table = $model->getTable();
            $plural = Str::plural(Str::snake(class_basename($model)));

            if (Schema::hasTable($table)) {
                continue;
            }

            // Nothing under the resolved name. If the pluralised name exists
            // then the model is missing a $table declaration.
            $declared = (new \ReflectionClass($model))->getDefaultProperties();

            if (Schema::hasTable($plural) && !array_key_exists('table', $declared)) {
                $mismatches[] = sprintf(
                    '%s resolves to "%s" but the table is "%s"',
                    $model::class,
                    $table,
                    $plural
                );
            } else {
                $missing[] = $model::class . ' -> ' . $table;
            }
        }

        $this->assertSame([], $mismatches, "Models pointing at the wrong table:\n  " . implode("\n  ", $mismatches));
        $this->assertSame([], $missing, "Models with no matching table:\n  " . implode("\n  ", $missing));
    }
}
