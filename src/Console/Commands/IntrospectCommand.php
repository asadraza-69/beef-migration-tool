<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Schema\Introspector;

class IntrospectCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $schema = $this->getOptionValue($args, '--schema');
        $pdo = $this->bootstrap->getPdo($databaseAlias);
        $introspector = new Introspector($pdo, $schema);

        $this->info("-- Introspecting database '{$databaseAlias}'" . ($schema ? " (schema: {$schema})" : "") . " --");

        $tables = $introspector->getTables();

        foreach ($tables as $table) {
            if ($table->name === 'nudelsalat_migrations') {
                continue;
            }

            $className = $this->classify($table->name);
            echo "Generating Model: {$className} (table: {$table->name})...\n";

            $code = $this->generateModelCode($className, $table);
            $path = $this->resolveOutputPath($className);

            if ($path === null) {
                echo "  No writable model directory configured. Outputting to console:\n";
                echo "========================================\n";
                echo $code . "\n";
                echo "========================================\n";
                continue;
            }

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $code);
            echo "  Saved to: {$path}\n";
        }
    }

    private function classify(string $name): string
    {
        $name = str_replace('_', ' ', $name);
        $name = ucwords($name);
        return str_replace(' ', '', $name);
    }

    private function generateModelCode(string $className, \Nudelsalat\Schema\Table $table): string
    {
        $fieldLines = [];
        $imports = [
            'Nudelsalat\\ORM\\Model',
        ];

        foreach ($table->getColumns() as $column) {
            [$fieldClass, $constructorArgs, $import] = $this->mapColumnToField($column);
            $imports[] = $import;
            $fieldLines[] = "            '{$column->name}' => new {$fieldClass}({$constructorArgs}),";
        }

        sort($imports);
        $importBlock = implode("\n", array_map(static fn(string $class): string => "use {$class};", array_unique($imports)));
        $fields = implode("\n", $fieldLines);

        return <<<PHP
<?php

namespace App\Models;

{$importBlock}

class {$className} extends Model
{
    public static string \$table = '{$table->name}';

    public static function fields(): array
    {
        return [
{$fields}
        ];
    }
}
PHP;
    }

    private function mapColumnToField(\Nudelsalat\Schema\Column $column): array
    {
        $type = strtolower($column->type);

        if ($type === 'int' || $type === 'integer') {
            return [
                'IntField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\IntField',
            ];
        }

        if ($type === 'bool' || $type === 'boolean') {
            return [
                'BooleanField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\BooleanField',
            ];
        }

        if ($type === 'decimal') {
            return [
                'DecimalField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\DecimalField',
            ];
        }

        if ($type === 'datetime' || $type === 'timestamp') {
            return [
                'DateTimeField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\DateTimeField',
            ];
        }

        if ($type === 'json') {
            return [
                'JSONField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\JSONField',
            ];
        }

        if ($type === 'text') {
            return [
                'TextField',
                $this->buildNamedArgs($column),
                'Nudelsalat\\Migrations\\Fields\\TextField',
            ];
        }

        $length = $this->extractVarcharLength($type);
        return [
            'StringField',
            $this->buildNamedArgs($column, ['length' => $length]),
            'Nudelsalat\\Migrations\\Fields\\StringField',
        ];
    }

    private function buildNamedArgs(\Nudelsalat\Schema\Column $column, array $extra = []): string
    {
        $args = $extra;

        if ($column->nullable) {
            $args['nullable'] = true;
        }
        if ($column->default !== null) {
            $args['default'] = $column->default;
        }
        if ($column->primaryKey) {
            $args['primaryKey'] = true;
        }
        if ($column->autoIncrement) {
            $args['autoIncrement'] = true;
        }

        if ($args === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn(string $key, mixed $value): string => "{$key}: " . var_export($value, true),
            array_keys($args),
            array_values($args)
        ));
    }

    private function extractVarcharLength(string $type): int
    {
        if (preg_match('/varchar\((\d+)\)/', $type, $matches) === 1) {
            return (int) $matches[1];
        }

        return 255;
    }

    private function resolveOutputPath(string $className): ?string
    {
        $apps = $this->bootstrap->getConfig()->getApps();
        if ($apps === []) {
            return null;
        }

        $app = array_key_first($apps);
        $dir = $apps[$app]['models'] ?? null;
        if (!$dir) {
            return null;
        }

        $parentDir = dirname($dir);
        if (!is_dir($parentDir) || !is_writable($parentDir)) {
            return null;
        }

        return rtrim($dir, '/') . '/' . $className . '.php';
    }
}
