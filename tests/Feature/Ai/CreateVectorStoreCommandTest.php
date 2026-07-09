<?php

use Illuminate\Support\Facades\File;
use Laravel\Ai\Stores;

beforeEach(fn () => skipUnlessAiSdkInstalled());

it('creates a vector store and prints its id plus env instructions', function () {
    Stores::fake();

    $this->artisan('boarddocs:vector-store:create', ['name' => 'Test Store'])
        ->expectsOutputToContain('Created vector store')
        ->expectsOutputToContain('BOARDDOCS_VECTOR_STORE_ID=')
        ->assertSuccessful();

    Stores::assertCreated('Test Store');
});

it('writes the resulting store id into .env when --write-env is passed', function () {
    Stores::fake();

    $envPath = base_path('.env');
    $original = File::exists($envPath) ? File::get($envPath) : null;
    File::put($envPath, "APP_NAME=Test\n");

    try {
        $this->artisan('boarddocs:vector-store:create', [
            'name' => 'Test Store',
            '--write-env' => true,
        ])->assertSuccessful();

        $contents = File::get($envPath);
        expect($contents)->toContain('BOARDDOCS_AI_SEARCH_DRIVER=vector');
        expect($contents)->toMatch('/BOARDDOCS_VECTOR_STORE_ID=fake_store_/');
        expect($contents)->toContain('APP_NAME=Test');
    } finally {
        $original === null ? File::delete($envPath) : File::put($envPath, $original);
    }
});

it('overwrites an existing BOARDDOCS_VECTOR_STORE_ID rather than duplicating it', function () {
    Stores::fake();

    $envPath = base_path('.env');
    $original = File::exists($envPath) ? File::get($envPath) : null;
    File::put($envPath, "BOARDDOCS_VECTOR_STORE_ID=old_value\n");

    try {
        $this->artisan('boarddocs:vector-store:create', [
            'name' => 'Test Store',
            '--write-env' => true,
        ])->assertSuccessful();

        $contents = File::get($envPath);
        expect(substr_count($contents, 'BOARDDOCS_VECTOR_STORE_ID='))->toBe(1);
        expect($contents)->not->toContain('old_value');
    } finally {
        $original === null ? File::delete($envPath) : File::put($envPath, $original);
    }
});

it('passes the --provider option through to Stores::create and writes it to .env', function () {
    Stores::fake();

    $envPath = base_path('.env');
    $original = File::exists($envPath) ? File::get($envPath) : null;
    File::put($envPath, "APP_NAME=Test\n");

    try {
        $this->artisan('boarddocs:vector-store:create', [
            'name' => 'Test Store',
            '--provider' => 'openai',
            '--write-env' => true,
        ])->assertSuccessful();

        expect(File::get($envPath))->toContain('BOARDDOCS_VECTOR_STORE_PROVIDER=openai');
    } finally {
        $original === null ? File::delete($envPath) : File::put($envPath, $original);
    }
});
