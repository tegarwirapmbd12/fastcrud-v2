<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tgrwirapmbd\CRUDGenerator\Commands\MakeCrud;

it('can generate crud with model name only', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string,content:text']);

    expect($this->app->basePath('app/Models/TestPost.php'))->toBeFile();
    expect($this->app->basePath('app/Http/Controllers/TestPostController.php'))->toBeFile();
    expect($this->app->basePath('resources/views/test_post/index.blade.php'))->toBeFile();
});

it('adds fillable property to model', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $modelPath = $this->app->basePath('app/Models/TestPost.php');

    if (file_exists($modelPath)) {
        $modelContent = file_get_contents($modelPath);
        expect($modelContent)->toContain('protected $fillable');
        expect($modelContent)->toContain("'title'");
    } else {
        $this->markTestSkipped('Model file not created');
    }
});

it('creates repository with crud methods', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $repositoryPath = $this->app->basePath('app/Repositories/TestPostRepository.php');

    if (file_exists($repositoryPath)) {
        $repositoryContent = file_get_contents($repositoryPath);
        expect($repositoryContent)->toContain('namespace App\Repositories;');
        expect($repositoryContent)->toContain('class TestPostRepository');
        expect($repositoryContent)->toContain('public function all(): Collection');
        expect($repositoryContent)->toContain('public function create(array $data): TestPost');
        expect($repositoryContent)->toContain('public function delete(int|string $id): bool');
    } else {
        $this->markTestSkipped('Repository file not created');
    }
});

it('creates controller with crud methods', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $controllerPath = $this->app->basePath('app/Http/Controllers/TestPostController.php');

    if (file_exists($controllerPath)) {
        $controllerContent = file_get_contents($controllerPath);
        expect($controllerContent)->toContain('public function index()');
        expect($controllerContent)->toContain('public function store(');
        expect(substr_count($controllerContent, 'public function update('))->toBe(1);
    } else {
        $this->markTestSkipped('Controller file not created');
    }
});

it('adds a sidebar navigation item with lucide icon', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $layoutPath = $this->app->basePath('resources/views/layouts/app.blade.php');

    if (file_exists($layoutPath)) {
        $layoutContent = file_get_contents($layoutPath);
        expect($layoutContent)->toContain('data-lucide="book-open-text"');
        expect($layoutContent)->toContain('Test Posts');
    } else {
        $this->markTestSkipped('Layout file not created');
    }
});

it('adds a sidebar navigation item with custom label and lucide icon', function (): void {
    Artisan::call('make:crud', [
        'name' => 'ProductCategory',
        '--fields' => 'name:string',
        '--sidenav-name' => 'Product Category',
        '--sidenav-icon' => 'package',
    ]);

    $layoutPath = $this->app->basePath('resources/views/layouts/app.blade.php');

    if (file_exists($layoutPath)) {
        $layoutContent = file_get_contents($layoutPath);
        expect($layoutContent)->toContain('data-lucide="package"');
        expect($layoutContent)->toContain('<span>Product Category</span>');
    } else {
        $this->markTestSkipped('Layout file not created');
    }
});

it('parses fields correctly', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('parseFields');

    $fields = 'title:string,content:text,author_id:integer,subtitle:string:nullable';
    $parsed = $method->invoke($command, $fields);

    expect($parsed)->toBeArray();
    expect($parsed)->toHaveCount(4);
    expect($parsed[0])->toMatchArray(['name' => 'title', 'type' => 'string']);
    expect($parsed[1])->toMatchArray(['name' => 'content', 'type' => 'text']);
    expect($parsed[2])->toMatchArray(['name' => 'author_id', 'type' => 'integer']);
    expect($parsed[3])->toMatchArray(['name' => 'subtitle', 'type' => 'string', 'nullable' => true]);
});

it('generates migration fields correctly', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('generateMigrationFields');

    $fields = 'title:string,content:text,subtitle:string:nullable';
    $result = $method->invoke($command, $fields);

    expect($result)->toContain('$table->string(\'title\')');
    expect($result)->toContain('$table->text(\'content\')');
    expect($result)->toContain('$table->string(\'subtitle\')->nullable()');
});

it('adds a unique standard uuid column after id in migrations', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('addUuidColumnToMigrationContent');

    $migrationContent = <<<'PHP'
Schema::create('test_posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->timestamps();
});
PHP;

    $result = $method->invoke($command, $migrationContent);

    expect($result)->toContain('$table->uuid(\'uuid\')->unique();');
    expect(strpos($result, '$table->id();'))->toBeLessThan(strpos($result, '$table->uuid(\'uuid\')->unique();'));
    expect(strpos($result, '$table->uuid(\'uuid\')->unique();'))->toBeLessThan(strpos($result, '$table->string(\'title\');'));
});

it('adds automatic unique uuid generation to the model', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('addUuidGenerationToModelContent');

    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestPost extends Model
{
    protected $fillable = ['title'];
}
PHP;

    $result = $method->invoke($command, $modelContent);

    expect($result)->toContain('protected static function booted(): void');
    expect($result)->toContain('(string) \Illuminate\Support\Str::uuid()');
    expect($result)->toContain("self::query()->where('uuid', \$uuid)->exists()");
    expect($result)->toContain('$model->uuid = $uuid;');
});

it('generates validation rules based on field types', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('getValidationRules');

    $fields = [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'content', 'type' => 'text'],
        ['name' => 'published', 'type' => 'integer'],
        ['name' => 'subtitle', 'type' => 'string', 'nullable' => true],
    ];

    $rules = $method->invoke($command, $fields);

    expect($rules)->toBeArray();
    expect($rules['title'])->toBe('required|string|max:255');
    expect($rules['content'])->toBe('required');
    expect($rules['published'])->toBe('required|integer');
    expect($rules['subtitle'])->toBe('nullable|string|max:255');
});

it('uses nullable validation config when generating migration fields', function (): void {
    config(['crud_generator.validation_rules.string' => 'nullable|string|max:255']);

    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('generateMigrationFields');

    $result = $method->invoke($command, 'title:string');

    expect($result)->toContain('$table->string(\'title\')->nullable()');
});
