<?php

declare(strict_types=1);

namespace Tgrwirapmbd\CRUDGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeCrud extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud
        {name? : The model name}
        {--fields= : Comma-separated fields in fieldName:fieldType format}
        {--api : Generate API controller}
        {--sidenav-name= : Custom sidebar navigation label}
        {--sidenav-icon= : Lucide icon name for the sidebar navigation item}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a model, migration, controller, and Blade views with specified fields for CRUD operations';

    protected array $validColumnTypes = [
        'string',
        'text',
        'longText',
        'integer',
        'bigInteger',
        'smallInteger',
        'tinyInteger',
        'boolean',
        'date',
        'dateTime',
        'timestamp',
        'decimal',
        'float',
        'double',
        'json',
        'jsonb',
    ];

    public function handle(): void
    {
        $name = $this->argument('name');
        $shouldPromptForSidenav = empty($name);

        if (! $name) {
            $name = text(
                label: 'Enter the model name',
                validate: ['name' => 'required|max:255|unique:users']
            );
        }

        $fields = $this->option('fields');
        $shouldPromptForSidenav = $shouldPromptForSidenav || empty($fields);

        if (empty($fields)) {
            $fields = $this->askForFields();
        }

        $migrationFields = ''; // ← inisialisasi di luar blok if

        if (config('crud_generator.generate_migration', true)) {
            $migrationFields = $this->generateMigrationFields($fields);
        }

        if (config('crud_generator.generate_model', true)) {
            $this->call('make:model', [
                'name' => $name,
                '--migration' => true,
            ]);

            $this->addFillableToModel($name, $fields);

            if (config('crud_generator.soft_deletes', false)) {
                $this->addSoftDeletesToModel($name);
            }
        }

        if (config('crud_generator.generate_repository', true)) {
            $this->createRepository($name);
        }

        if (config('crud_generator.generate_migration', true)) {
            $this->updateMigrationFile($name, $migrationFields);
        }

        if (config('crud_generator.generate_controller', true)) {
            $this->call('make:controller', [
                'name' => $name.'Controller',
                '--resource' => false,
            ]);

            $this->updateController($name, $fields);
        }

        if (config('crud_generator.generate_blade', true)) {
            $sidenav = [
                'name' => $this->getSidebarLabel($name),
                'icon' => $this->getSidebarIcon($name),
            ];

            if (config('crud_generator.generate_sidenav', true)) {
                $sidenav = $this->resolveSidenavOptions($name, $shouldPromptForSidenav);
            }

            $this->createBladeViews($name, $fields, $sidenav);
        }

        if (config('crud_generator.generate_routes', true)) {
            $this->addResourceRoute($name);
        }

        if ($this->option('api') || config('crud_generator.api_controller', false)) {
            $this->createApiController($name, $fields);
            $this->addApiResourceRoute($name);
        }

        $this->info(sprintf('CRUD operations for %s created successfully. now you can view %s', $name, url(Str::pluralStudly(Str::snake($name)))));
    }

    protected function askForFields(): string
    {
        $fields = [];
        while (true) {
            $fieldName = text('Enter a field name or leave blank to finish');
            if ($fieldName === '' || $fieldName === '0') {
                break;
            }

            // Prompt to select the field type from the options
            $fieldType = select('Select field type', $this->validColumnTypes);
            $isNullable = confirm(label: 'Should this field be nullable?', default: false);

            // Validate and add the field
            if ($this->validateField($fieldName, $fieldType)) {
                $field = sprintf('%s:%s', $fieldName, $fieldType);

                if ($isNullable) {
                    $field .= ':nullable';
                }

                $fields[] = $field;
            }
        }

        return implode(',', $fields);
    }

    protected function validateField(string $fieldName, string $fieldType): bool
    {
        $fieldType = trim($fieldType);

        if (! in_array($fieldType, $this->validColumnTypes)) {
            $this->error(sprintf("Invalid field type '%s'. Valid types are: %s", $fieldType, implode(', ', $this->validColumnTypes)));

            return false;
        }

        return true;
    }

    protected function generateMigrationFields(string $fields): string
    {
        $migrationFields = '';

        if ($fields !== '' && $fields !== '0') {
            foreach ($this->parseFields($fields) as $field) {
                $fieldName = $field['name'];
                $fieldType = $field['type'];
                $nullable = $this->fieldShouldBeNullable($field) ? '->nullable()' : '';

                $migrationFields .= "\$table->{$fieldType}('{$fieldName}'){$nullable};\n            ";
            }
        }

        return $migrationFields;
    }

    protected function updateMigrationFile(string $name, string $migrationFields): void
    {
        $className = Str::pluralStudly($name);
        $timestamp = now()->format('Y_m_d_His');
        $migrationFile = database_path(sprintf('migrations/%s_create_%s_table.php', $timestamp, Str::snake($className)));

        if (file_exists($migrationFile)) {
            $migrationContent = file_get_contents($migrationFile);
            $migrationContent = $this->addUuidColumnToMigrationContent((string) $migrationContent);

            $softDeletesColumn = '';
            if (config('crud_generator.soft_deletes', false)) {
                $softDeletesColumn = "\$table->softDeletes();\n            ";
            }

            $migrationContent = str_replace(
                '$table->timestamps();',
                rtrim($migrationFields)."\n            ".$softDeletesColumn.'$table->timestamps();',
                $migrationContent
            );
            file_put_contents($migrationFile, $migrationContent);
        }

        if ($this->laravel->environment('testing')) {
            return;
        }

        $tableName = Str::snake($className);
        if (! Schema::hasTable($tableName)) {
            $this->runMigration($migrationFile);
        } else {
            $this->info(sprintf("Table '%s' already exists. Migration will not run.", $tableName));
        }
    }

    protected function addUuidColumnToMigrationContent(string $migrationContent): string
    {
        if (preg_match('/\$table->\w+\([\'"]uuid[\'"]/', $migrationContent) === 1) {
            return $migrationContent;
        }

        return str_replace(
            '$table->id();',
            "\$table->id();\n            \$table->uuid('uuid')->unique();",
            $migrationContent
        );
    }

    protected function runMigration(string $migrationFile): void
    {
        $this->info('Running migration...');

        Artisan::call('migrate', [
            '--path' => 'database/migrations/'.basename($migrationFile),
            '--quiet' => true,
        ]);

        $this->info('Migration ran successfully.');
    }

    protected function addFillableToModel(string $name, string $fields): void
    {
        $modelFile = app_path(sprintf('Models/%s.php', $name));

        if (file_exists($modelFile)) {
            $fillableFields = implode("', '", array_map(fn ($field): string => trim(explode(':', $field)[0]), explode(',', $fields)));

            $modelContent = (string) file_get_contents($modelFile);
            $originalModelContent = $modelContent;

            if (! preg_match('/protected\s+\$fillable\s*=\s*\[/', $modelContent)) {
                $modelContent = preg_replace(
                    '/class\s+'.preg_quote($name, '/').'\s*extends\s+Model\s*{/',
                    'class '.$name.' extends Model {'."\n\n    protected \$fillable = ['{$fillableFields}'];\n",
                    $modelContent
                );
            }

            $modelContent = $this->addUuidGenerationToModelContent($modelContent);

            if ($modelContent !== $originalModelContent) {
                file_put_contents($modelFile, $modelContent);
            }
        }
    }

    protected function addUuidGenerationToModelContent(string $modelContent): string
    {
        if (str_contains($modelContent, '$model->uuid')) {
            return $modelContent;
        }

        if (preg_match('/protected\s+static\s+function\s+booted\s*\(/', $modelContent) === 1) {
            return $modelContent;
        }

        $uuidGeneration = <<<'PHP'

    protected static function booted(): void
    {
        static::creating(function ($model): void {
            if (! empty($model->uuid)) {
                return;
            }

            do {
                $uuid = (string) \Illuminate\Support\Str::uuid();
            } while (self::query()->where('uuid', $uuid)->exists());

            $model->uuid = $uuid;
        });
    }

PHP;
        $classEndPos = strrpos($modelContent, '}');

        if ($classEndPos === false) {
            return $modelContent;
        }

        return substr_replace($modelContent, $uuidGeneration, $classEndPos, 0);
    }

    protected function addSoftDeletesToModel(string $name): void
    {
        $modelFile = app_path(sprintf('Models/%s.php', $name));

        if (file_exists($modelFile)) {
            $modelContent = file_get_contents($modelFile);

            if (! str_contains($modelContent, 'use SoftDeletes;') && ! str_contains($modelContent, SoftDeletes::class)) {
                $modelContent = preg_replace(
                    '/namespace App\\\\Models;/',
                    "namespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;",
                    $modelContent
                );

                $modelContent = preg_replace(
                    '/class\s+'.preg_quote($name, '/').'\s+extends\s+Model\s*{/',
                    'class '.$name.' extends Model'."\n".'{'."\n    use SoftDeletes;\n",
                    (string) $modelContent
                );

                file_put_contents($modelFile, $modelContent);
            }
        }
    }

    protected function createRepository(string $name): void
    {
        $repositoryDirectory = app_path('Repositories');

        if (! is_dir($repositoryDirectory)) {
            mkdir($repositoryDirectory, 0755, true);
        }

        $repositoryFile = $repositoryDirectory.'/'.$name.'Repository.php';

        if (file_exists($repositoryFile)) {
            $this->warn(sprintf('Repository for %s already exists.', $name));

            return;
        }

        $repositoryContent = str_replace(
            '{{ name }}',
            $name,
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\{{ name }};
use Illuminate\Database\Eloquent\Collection;

class {{ name }}Repository
{
    public function all(): Collection
    {
        return {{ name }}::all();
    }

    public function find(int|string $uuid): {{ name }}
    {
        return {{ name }}::findOrFail($uuid);
    }

    public function create(array $data): {{ name }}
    {
        return {{ name }}::create($data);
    }

    public function update(int|string $uuid, array $data): {{ name }}
    {
        $item = $this->find($uuid);
        $item->update($data);

        return $item;
    }

    public function delete(int|string $uuid): bool
    {
        return (bool) $this->find($uuid)->delete();
    }
}
PHP
        );

        file_put_contents($repositoryFile, $repositoryContent);

        $this->info(sprintf('Repository for %s created successfully.', $name));
    }

    protected function createBladeViews(string $name, string $fields, array $sidenav): void
    {
        $fieldsArray = $this->parseFields($fields);
        $viewsDirectory = resource_path('views/backend/'.Str::snake($name));

        if (! is_dir($viewsDirectory)) {
            mkdir($viewsDirectory, 0755, true);
        }

        $stubPath = __DIR__.'/../../stubs/';
        $pluralRouteName = Str::pluralStudly(Str::snake($name));
        $namaIndonesia = $sidenav['name'];

        $replacements = [
            '{{ name }}' => $name,
            '{{ Name }}' => ucfirst($name),
            '{{ plural_route_name }}' => $pluralRouteName,
            '{{ NamaIndonesia }}' => $namaIndonesia,
        ];

        $this->generateViewFromStub($stubPath.'index.blade.stub', $viewsDirectory.'/index.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'create.blade.stub', $viewsDirectory.'/create.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'edit.blade.stub', $viewsDirectory.'/edit.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'show.blade.stub', $viewsDirectory.'/show.blade.php', $replacements, $fieldsArray);

        $layoutsDirectory = resource_path('views/layouts');
        if (! is_dir($layoutsDirectory)) {
            mkdir($layoutsDirectory, 0755, true);
        }

        if (! file_exists($layoutsDirectory.'/app.blade.php')) {
            copy($stubPath.'app.blade.stub', $layoutsDirectory.'/app.blade.php');
        }

        if (config('crud_generator.generate_sidenav', true)) {
            $this->updateAppLayout($name, $sidenav['name'], $sidenav['icon']);
        }

        $this->info(sprintf('Blade views for %s created successfully.', $name));
    }

    protected function resolveSidenavOptions(string $name, bool $shouldPrompt): array
    {
        $defaultName = $this->getSidebarLabel($name);
        $defaultIcon = $this->getSidebarIcon($name);

        $sidenavName = $this->option('sidenav-name');
        $sidenavIcon = $this->option('sidenav-icon');

        if ($shouldPrompt && empty($sidenavName)) {
            $sidenavName = text(
                label: 'Nama Sidenav',
                placeholder: $defaultName,
                default: $defaultName,
                validate: ['sidenav_name' => 'required|max:255']
            );
        }

        if ($shouldPrompt && empty($sidenavIcon)) {
            $sidenavIcon = text(
                label: 'Nama Icon',
                placeholder: $defaultIcon,
                default: $defaultIcon,
                hint: 'Gunakan nama icon dari https://lucide.dev/icons'
            );
        }

        return [
            'name' => $this->normalizeSidenavName($sidenavName, $defaultName),
            'icon' => $this->normalizeLucideIconName($sidenavIcon, $defaultIcon),
        ];
    }

    protected function updateAppLayout(string $name, string $sidenavName, string $sidenavIcon): void
    {
        $layoutFile = $this->resolveSidenavLayoutFile();

        if ($layoutFile === null) {
            $this->warn('Sidenav tidak diupdate: file partial sidenav tidak ditemukan. '
                .'Set config(\'crud_generator.sidenav_path\') agar sesuai struktur project Anda.');

            return;
        }

        $layoutContent = (string) file_get_contents($layoutFile);
        $routeName = Str::pluralStudly(Str::snake($name));

        if (str_contains($layoutContent, sprintf("route('%s.index')", $routeName))) {
            $this->warn(sprintf('Menu sidenav untuk %s sudah ada, dilewati.', $name));

            return;
        }

        $sidebarLabel = htmlspecialchars($sidenavName, ENT_QUOTES, 'UTF-8');
        $sidebarIcon = htmlspecialchars($sidenavIcon, ENT_QUOTES, 'UTF-8');
        $indent = $this->detectMenuItemIndent($layoutContent);

        $menuItem = $indent."<li class=\"side-nav-item\">\n"
            .$indent."    <a class=\"side-nav-link\" href=\"{{ route('{$routeName}.index') }}\">\n"
            .$indent."        <span class=\"menu-icon\"><i data-lucide=\"{$sidebarIcon}\"></i></span>\n"
            .$indent."        <span class=\"menu-text\">{$sidebarLabel}</span>\n"
            .$indent."    </a>\n"
            .$indent.'</li>';

        $updatedContent = $this->insertSidenavMenuItem($layoutContent, $menuItem);

        file_put_contents($layoutFile, $updatedContent);
        $this->info(sprintf('Menu sidenav untuk %s berhasil ditambahkan ke %s.', $name, $layoutFile));
    }

    protected function resolveSidenavLayoutFile(): ?string
    {
        $configuredPath = config('crud_generator.sidenav_path');

        $candidates = array_filter([
            $configuredPath ? resource_path($configuredPath) : null,
            resource_path('views/shared/partials/sidenav.blade.php'),
            resource_path('views/layouts/partials/sidenav.blade.php'),
            resource_path('views/partials/sidenav.blade.php'),
        ]);

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function detectMenuItemIndent(string $content, int $fallbackSpaces = 16): string
    {
        if (preg_match('/^([ \t]*)<li class="side-nav-item">/m', $content, $matches)) {
            return $matches[1];
        }

        return str_repeat(' ', $fallbackSpaces);
    }

    protected function insertSidenavMenuItem(string $layoutContent, string $menuItem): string
    {
        $patterns = [
            '/[ \t]*<!--\s*crud-generator-sidenav-items\s*-->/',
            '/[ \t]*<!--\s*sidebar-items\s*-->/',
            '/[ \t]*<li class="side-nav-title[^>]*data-lang="custom-pages"[^>]*>.*?<\/li>/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $layoutContent, $match, PREG_OFFSET_CAPTURE)) {
                return substr_replace($layoutContent, $menuItem."\n", (int) $match[0][1], 0);
            }
        }

        // Sisipkan tepat sebelum </ul> penutup <ul class="side-nav">, bukan di akhir file.
        if (preg_match('/<ul class="side-nav">/', $layoutContent, $openMatch, PREG_OFFSET_CAPTURE)) {
            $searchFrom = $openMatch[0][1] + strlen($openMatch[0][0]);
            $closingPosition = strpos($layoutContent, '</ul>', $searchFrom);

            if ($closingPosition !== false) {
                $lineStart = strrpos(substr($layoutContent, 0, $closingPosition), "\n");
                $insertAt = $lineStart === false ? $closingPosition : $lineStart + 1;

                return substr_replace($layoutContent, $menuItem."\n", $insertAt, 0);
            }
        }

        // Last resort yang aman: tetap di dalam <ul class="side-nav">, tidak pernah di luar tag manapun.
        if (preg_match('/<ul class="side-nav">/', $layoutContent, $match, PREG_OFFSET_CAPTURE)) {
            $insertAt = $match[0][1] + strlen($match[0][0]);

            return substr_replace($layoutContent, "\n".$menuItem, $insertAt, 0);
        }

        return $layoutContent;
    }

    protected function getSidebarLabel(string $name): string
    {
        $pluralName = Str::plural(Str::snake($name));

        return ucwords(str_replace('_', ' ', $pluralName));
    }

    protected function getSidebarIcon(string $name): string
    {
        $iconMap = [
            'user' => 'users',
            'post' => 'book-open-text',
            'article' => 'file-text',
            'product' => 'package',
            'order' => 'shopping-cart',
            'setting' => 'settings',
            'dashboard' => 'layout-dashboard',
            'category' => 'folder',
        ];

        $normalizedName = Str::snake($name);

        foreach ($iconMap as $keyword => $icon) {
            if (str_contains($normalizedName, $keyword)) {
                return $icon;
            }
        }

        return config('crud_generator.sidenav_default_icon', 'book-open-text');
    }

    protected function normalizeSidenavName(mixed $sidenavName, string $fallback): string
    {
        $sidenavName = trim((string) $sidenavName);

        return $sidenavName !== '' ? $sidenavName : $fallback;
    }

    protected function normalizeLucideIconName(mixed $sidenavIcon, string $fallback): string
    {
        $sidenavIcon = strtolower(trim((string) $sidenavIcon));
        $sidenavIcon = str_replace(['_', ' '], '-', $sidenavIcon);
        $sidenavIcon = preg_replace('/[^a-z0-9-]/', '', $sidenavIcon) ?: '';
        $sidenavIcon = preg_replace('/-+/', '-', $sidenavIcon) ?: '';
        $sidenavIcon = trim($sidenavIcon, '-');

        return $sidenavIcon !== '' ? $sidenavIcon : $fallback;
    }

    protected function generateViewFromStub(string $stubPath, string $targetPath, array $replacements, array $fields): void
    {
        if (! file_exists($stubPath)) {
            $this->error('Stub file not found: '.$stubPath);

            return;
        }

        $content = file_get_contents($stubPath);

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        $content = $this->replaceFieldPlaceholders($content, $fields);

        file_put_contents($targetPath, $content);
    }

    protected function replaceFieldPlaceholders(string $content, array $fields): string
    {
        $tableHeaders = '';
        $tableData = '';
        $formFields = '';
        $searchFields = '';

        $isEditStub = str_contains($content, "@method('PUT')");

        foreach ($fields as $field) {
            $fieldName = $field['name'];

            // Table headers
            $tableHeaders .= "                                                <th>{{ ucfirst('{$fieldName}') }}</th>\n";

            // Table data
            $tableData .= "                                                    <td>{{ \$item->{$fieldName} }}</td>\n";

            // Form fields
            $formFields .= "                                    <div class=\"form-group mb-2\">\n";
            $formFields .= "                                        <label for=\"{$fieldName}\">{{ ucfirst('{$fieldName}') }}</label>\n";
            $formFields .= "                                        <input type=\"text\" class=\"form-control\" name=\"{$fieldName}\" id=\"{$fieldName}\"";

            if ($isEditStub) {
                $formFields .= " value=\"{{ old('{$fieldName}', \$item->{$fieldName} ?? '') }}\"";
            }

            $formFields .= ">\n";
            $formFields .= "                                    </div>\n";

            // Search fields (untuk index)
            $searchFields .= "                                    <div class=\"col-md-6\">\n";
            $searchFields .= "                                        <label class=\"form-label\">{{ ucfirst('{$fieldName}') }}</label>\n";
            $searchFields .= "                                        <input type=\"text\" name=\"search_{$fieldName}\" class=\"form-control me-2\" placeholder=\"Cari berdasarkan {$fieldName}...\" value=\"{{ request('search_{$fieldName}') }}\">\n";
            $searchFields .= "                                    </div>\n";
        }

        $content = str_replace('{{ table_headers }}', trim($tableHeaders), $content);
        $content = str_replace('{{ table_data }}', trim($tableData), $content);
        $content = str_replace('{{ form_fields }}', trim($formFields), $content);
        $content = str_replace('{{ search_fields }}', trim($searchFields), $content);

        return $content;
    }

    protected function parseFields(string $fields): array
    {
        $parsed = [];

        if ($fields === '' || $fields === '0') {
            return $parsed;
        }

        $fieldArray = explode(',', $fields);

        foreach ($fieldArray as $field) {
            if (trim($field) === '') {
                continue;
            }

            $parts = explode(':', $field);
            $modifiers = array_values(array_filter(array_map(
                fn (string $modifier): string => strtolower(trim($modifier)),
                array_slice($parts, 2)
            )));

            $parsed[] = [
                'name' => trim($parts[0]),
                'type' => trim($parts[1] ?? '') !== '' ? trim($parts[1]) : 'string',
                'nullable' => in_array('nullable', $modifiers, true),
                'modifiers' => $modifiers,
            ];
        }

        return $parsed;
    }

    protected function generateEditFormFields(array $fieldsArray): string
    {
        $fieldsHtml = '';

        foreach ($fieldsArray as $field) {
            $fieldParts = explode(':', (string) $field);
            $fieldName = trim($fieldParts[0]);

            $fieldsHtml .= "<div class=\"form-group\">\n";
            $fieldsHtml .= sprintf('    <label for="%s">%s</label>\n', $fieldName, ucfirst($fieldName));
            $fieldsHtml .= "    <input type=\"text\" class=\"form-control\" name=\"{$fieldName}\" id=\"{$fieldName}\" value=\"{{ old('{$fieldName}', \$item->{$fieldName}) }}\">\n";
            $fieldsHtml .= "</div>\n";
        }

        return $fieldsHtml;
    }

    protected function updateController(string $name, string $fields): void
    {
        $controllerFile = app_path(sprintf('Http/Controllers/%sController.php', $name));
        $modelNamespace = '\App\Models\\'.$name; // Define the model namespace

        if (file_exists($controllerFile)) {
            $fieldsArray = $this->parseFields($fields);
            $fieldNames = array_map(fn (array $field) => $field['name'], $fieldsArray);

            // Read the existing controller content
            $originalControllerContent = (string) file_get_contents($controllerFile);
            $controllerContent = $this->removeDuplicateControllerMethods($originalControllerContent, ['update']);
            $duplicateMethodsWereRemoved = $controllerContent !== $originalControllerContent;

            // Remove commented-out lines starting with //
            $controllerContent = preg_replace('/^\s*\/\/.*$/m', '', $controllerContent);

            // Remove any line breaks before the 'index' method
            $controllerContent = preg_replace('/\n\s*\n(?=\s*public function index)/', "\n", (string) $controllerContent);

            $methods = [
                'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
            ];
            $existingMethods = [];

            // Check existing methods
            foreach ($methods as $method) {
                if (str_contains((string) $controllerContent, sprintf('public function %s(', $method))) {
                    $existingMethods[] = $method;
                }
            }

            // Create the new methods string
            $newMethods = '';
            if (! in_array('index', $existingMethods)) {
                $newMethods .= '    public function index()
    {
        $items = '.$modelNamespace.'::all();
        return view(\'backend.'.Str::snake($name).'.index\', compact(\'items\'));
    }'."\n";
            }

            if (! in_array('create', $existingMethods)) {
                $newMethods .= '
    public function create()
    {
        return view(\'backend.'.Str::snake($name).'.create\');
    }'."\n";
            }

            if (! in_array('store', $existingMethods)) {
                $validationRules = $this->getValidationRules($fieldsArray);
                $newMethods .= '
    public function store(Request $request)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames)).'
        ]);

        '.$modelNamespace.'::create($validated);
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            if (! in_array('update', $existingMethods)) {
                $validationRules = $this->getValidationRules($fieldsArray);
                $newMethods .= '
    public function update(Request $request, $uuid)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames)).'
        ]);

        $item = '.$modelNamespace.'::findOrFail($uuid);
        $item->update($validated);
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            if (! in_array('show', $existingMethods)) {
                $newMethods .= '
    public function show($uuid)
    {
        $item = '.$modelNamespace.'::findOrFail($uuid);
        return view(\'backend.'.Str::snake($name).'.show\', compact(\'item\'));
    }'."\n";
            }

            if (! in_array('edit', $existingMethods)) {
                $newMethods .= '
    public function edit($uuid)
    {
        $item = '.$modelNamespace.'::findOrFail($uuid);
        return view(\'backend.'.Str::snake($name).'.edit\', compact(\'item\'));
    }'."\n";
            }

            if (! in_array('destroy', $existingMethods)) {
                $newMethods .= '
    public function destroy($uuid)
    {
        $item = '.$modelNamespace.'::findOrFail($uuid);
        $item->delete();
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            // If new methods are generated, append them just before the closing bracket of the class
            if ($newMethods !== '' && $newMethods !== '0') {
                // Find the position of the last closing bracket of the class
                $classEndPos = strrpos((string) $controllerContent, '}');
                if ($classEndPos !== false) {
                    // Insert the new methods before the closing bracket
                    $controllerContent = substr_replace($controllerContent, $newMethods."\n", $classEndPos, 0);
                    file_put_contents($controllerFile, $controllerContent);
                    $this->info(sprintf('CRUD methods for %s added to the controller.', $name));
                }
            } elseif ($duplicateMethodsWereRemoved) {
                file_put_contents($controllerFile, $controllerContent);
                $this->info(sprintf('Duplicate CRUD methods for %s cleaned up in the controller.', $name));
            } else {
                $this->warn(sprintf('CRUD methods for %s already exist in the controller.', $name));
            }
        }
    }

    protected function removeDuplicateControllerMethods(string $controllerContent, array $methods): string
    {
        foreach ($methods as $method) {
            while (true) {
                $methodBlocks = $this->findControllerMethodBlocks($controllerContent, $method);

                if (count($methodBlocks) <= 1) {
                    break;
                }

                $duplicateBlock = $methodBlocks[1];
                $controllerContent = substr_replace(
                    $controllerContent,
                    '',
                    $duplicateBlock['start'],
                    $duplicateBlock['end'] - $duplicateBlock['start']
                );
            }
        }

        return $controllerContent;
    }

    protected function findControllerMethodBlocks(string $controllerContent, string $method): array
    {
        preg_match_all(
            '/public\s+function\s+'.preg_quote($method, '/').'\s*\(/',
            $controllerContent,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $methodBlocks = [];

        foreach ($matches[0] as $match) {
            $methodStart = (int) $match[1];
            $openBrace = strpos($controllerContent, '{', $methodStart);

            if ($openBrace === false) {
                continue;
            }

            $closeBrace = $this->findMatchingBrace($controllerContent, $openBrace);

            if ($closeBrace === null) {
                continue;
            }

            $lineStart = strrpos(substr($controllerContent, 0, $methodStart), "\n");
            $blockStart = $lineStart === false ? $methodStart : $lineStart + 1;

            $methodBlocks[] = [
                'start' => $blockStart,
                'end' => $closeBrace + 1,
            ];
        }

        return $methodBlocks;
    }

    protected function findMatchingBrace(string $content, int $openBrace): ?int
    {
        $depth = 0;
        $length = strlen($content);

        for ($position = $openBrace; $position < $length; $position++) {
            if ($content[$position] === '{') {
                $depth++;
            }

            if ($content[$position] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }

    protected function getValidationRules(array $fields): array
    {
        $rules = [];
        $configRules = config('crud_generator.validation_rules', []);
        $defaultRule = $configRules['default'] ?? 'required';

        foreach ($fields as $field) {
            $fieldType = $field['type'];
            $fieldName = $field['name'];

            if (isset($configRules[$fieldType])) {
                $rule = $configRules[$fieldType];
            } elseif (str_contains((string) $fieldName, 'email')) {
                $rule = $configRules['email'] ?? 'required|email';
            } else {
                $rule = $defaultRule;
            }

            if ($field['nullable'] ?? false) {
                $rule = $this->makeValidationRuleNullable((string) $rule);
            }

            $rules[$fieldName] = $rule;
        }

        return $rules;
    }

    protected function fieldShouldBeNullable(array $field): bool
    {
        if ($field['nullable'] ?? false) {
            return true;
        }

        $fieldName = $field['name'];
        $validationRules = $this->getValidationRules([$field]);

        return $this->validationRuleContainsNullable((string) ($validationRules[$fieldName] ?? ''));
    }

    protected function makeValidationRuleNullable(string $rule): string
    {
        $rules = array_values(array_filter(
            array_map('trim', explode('|', $rule)),
            fn (string $rule): bool => $rule !== '' && $rule !== 'required' && $rule !== 'nullable'
        ));

        array_unshift($rules, 'nullable');

        return implode('|', $rules);
    }

    protected function validationRuleContainsNullable(string $rule): bool
    {
        return in_array('nullable', array_map('trim', explode('|', $rule)), true);
    }

    protected function addResourceRoute(string $name): void
    {
        $routeFile = base_path('routes/web.php');
        $controllerClass = sprintf('\App\Http\Controllers\%sController', $name);

        // Define the resource route string
        $resourceRoute = sprintf("Route::resource('%s', %s::class);", Str::pluralStudly(Str::snake($name)), $controllerClass);

        // Check if the route already exists
        if (file_exists($routeFile)) {
            $routesContent = file_get_contents($routeFile);

            // Add the resource route if it doesn't already exist
            if (! str_contains($routesContent, $resourceRoute)) {
                // Append the new resource route to the file
                file_put_contents($routeFile, "\n".$resourceRoute."\n", FILE_APPEND);
                $this->info(sprintf('Resource route for %s added to routes/web.php.', $name));
            } else {
                $this->warn(sprintf('Resource route for %s already exists in routes/web.php.', $name));
            }
        }
    }

    protected function createApiController(string $name, string $fields): void
    {
        $this->call('make:controller', [
            'name' => sprintf('Api/%sController', $name),
            '--api' => true,
        ]);

        $controllerFile = app_path(sprintf('Http/Controllers/Api/%sController.php', $name));
        $modelNamespace = '\App\Models\\'.$name;
        $fieldsArray = $this->parseFields($fields);
        $fieldNames = array_map(fn (array $field) => $field['name'], $fieldsArray);
        $validationRules = $this->getValidationRules($fieldsArray);

        if (file_exists($controllerFile)) {
            $controllerContent = file_get_contents($controllerFile);
            $validationArray = implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames));

            $storeMethod = "
    public function store(Request \$request)
    {
        \$validated = \$request->validate([
            {$validationArray}
        ]);

        \$item = {$modelNamespace}::create(\$validated);
        return response()->json(\$item, 201);
    }
";
            $updateMethod = "
    public function update(Request \$request, \$uuid)
    {
        \$validated = \$request->validate([
            {$validationArray}
        ]);

        \$item = {$modelNamespace}::findOrFail(\$uuid);
        \$item->update(\$validated);
        return response()->json(\$item);
    }
";

            if (! str_contains($controllerContent, 'public function store(')) {
                $controllerContent = str_replace(
                    '}',
                    $storeMethod."\n}",
                    $controllerContent
                );
            }

            if (! str_contains($controllerContent, 'public function update(')) {
                $controllerContent = str_replace(
                    '}',
                    $updateMethod."\n}",
                    $controllerContent
                );
            }

            file_put_contents($controllerFile, $controllerContent);
            $this->info(sprintf('API controller for %s created successfully.', $name));
        }
    }

    protected function addApiResourceRoute(string $name): void
    {
        $routeFile = base_path('routes/api.php');
        $controllerClass = sprintf('\App\Http\Controllers\Api\%sController', $name);
        $resourceRoute = sprintf("Route::apiResource('%s', %s::class);", Str::pluralStudly(Str::snake($name)), $controllerClass);

        if (file_exists($routeFile)) {
            $routesContent = file_get_contents($routeFile);

            if (! str_contains($routesContent, $resourceRoute)) {
                file_put_contents($routeFile, "\n".$resourceRoute."\n", FILE_APPEND);
                $this->info(sprintf('API resource route for %s added to routes/api.php.', $name));
            } else {
                $this->warn(sprintf('API resource route for %s already exists in routes/api.php.', $name));
            }
        }
    }
}
