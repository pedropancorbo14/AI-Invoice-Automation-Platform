<?php

namespace Tests;

use App\Support\Extraction\LLMProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeLLMProvider;

abstract class TestCase extends BaseTestCase
{
    protected FakeLLMProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test llama a la API de verdad.
        $this->llm = new FakeLLMProvider;
        $this->app->instance(LLMProvider::class, $this->llm);
    }
}
