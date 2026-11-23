<?php

namespace Tests;

use App\Support\Extraction\LLMProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\FakeLLMProvider;

abstract class TestCase extends BaseTestCase
{
    protected FakeLLMProvider $llm;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // Antes de que RefreshDatabase migre nada: los tests vacían la base de datos.
        self::ensureTestDatabase($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test llama a la API de verdad.
        $this->llm = new FakeLLMProvider;
        $this->app->instance(LLMProvider::class, $this->llm);
    }

    /**
     * Los tests borran la base de datos en cada ejecución. Solo se permite
     * SQLite en memoria o una base cuyo nombre acabe en "_test" (la de la CI), nunca la de
     * desarrollo: dentro del contenedor, DB_CONNECTION y DB_DATABASE llegan del
     * .env y tienen prioridad sobre phpunit.xml.
     */
    private static function ensureTestDatabase(Application $app): void
    {
        $config = $app->make('config');
        $connection = (string) $config->get('database.default');
        $driver = (string) $config->get("database.connections.{$connection}.driver");
        $database = (string) $config->get("database.connections.{$connection}.database");

        if ($database === ':memory:' || str_ends_with($database, '_test')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Los tests no pueden usar la base de datos "%s" (%s): la vaciarían. '
            .'Usa SQLite en memoria (DB_CONNECTION=sqlite DB_DATABASE=:memory:) o una base cuyo nombre acabe en "_test".',
            $database,
            $driver,
        ));
    }
}
