<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Schema\Introspector;

class DumpDataCommand extends BaseCommand
{
    private array $excludeModels = [];
    private ?string $outputFile = null;
    private string $format = 'json';
    private bool $indent = true;
    private bool $naturalSort = true;
    private bool $naturalPrimary = false;
    private bool $naturalForeign = false;
    private ?string $pks = null;
    private bool $verbosity = true;

    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $pdo = $this->bootstrap->getPdo($databaseAlias);
        $config = $this->bootstrap->getConfig();
        $schema = $this->getOptionValue($args, '--schema');

        // Parse options
        $this->excludeModels = array_filter(explode(',', $this->getOptionValue($args, '--exclude') ?? ''));
        $this->outputFile = $this->getOptionValue($args, '--output');
        $this->format = $this->getOptionValue($args, '--format') ?? 'json';
        $this->indent = !$this->hasFlag($args, '--no-indent');
        $this->naturalPrimary = $this->hasFlag($args, '--natural-primary');
        $this->naturalForeign = $this->hasFlag($args, '--natural-foreign');
        $this->pks = $this->getOptionValue($args, '--pks');
        $this->verbosity = !$this->hasFlag($args, '--quiet');
        
        // Handle --indent=N
        $indentValue = $this->getOptionValue($args, '--indent');
        if ($indentValue !== null) {
            $this->indent = (int)$indentValue > 0;
        }

        // Get positional args
        $positional = $this->getPositionalArgs($args);

        // Handle compressed output extension
        $this->outputFile = $this->resolveCompressedOutput($this->outputFile);

        try {
            if (!empty($positional)) {
                $target = $positional[0];
                if (str_contains($target, '.')) {
                    $data = $this->dumpSingleModel($pdo, $target, $schema);
                } else {
                    $data = $this->dumpApp($pdo, $target, $schema);
                }
            } else {
                $data = $this->dumpAllModels($pdo, $schema);
            }

            $output = $this->formatOutput($data);
            
            if ($this->outputFile) {
                $path = $this->resolveOutputPath($this->outputFile, $config);
                $dir = dirname($path);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                
                // Handle compressed output
                $this->writeCompressed($path, $output);
                
                if ($this->verbosity) {
                    $this->info("Dumped " . count($data) . " records to {$this->outputFile}");
                }
            } else {
                echo $output . "\n";
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

private function resolveCompressedOutput(?string $output): ?string
    {
        if (!$output) return null;
        
        // Check for .json.gz, .json.bz2, .json.lzma, .json.xz
        if (preg_match('/\.json\.(gz|bz2|lzma|xz)$/', $output)) {
            return $output;
        }
        
        return $output;
    }

    private function writeCompressed(string $path, string $data): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        match($extension) {
            'gz' => file_put_contents($path, gzencode($data)),
            'bz2' => file_put_contents($path, bzcompress($data)),
            'lzma' => file_put_contents($path, lzma_encode($data)),
            'xz' => file_put_contents($path, $this->xzEncode($data)),
            default => file_put_contents($path, $data),
        };
    }

    private function xzEncode(string $data): string
    {
        if (!function_exists('xz_encodestring')) {
            // Fallback: just return uncompressed if xz not available
            return $data;
        }
        return xz_encodestring($data);
    }

    private function dumpSingleModel(\PDO $pdo, string $modelArg, ?string $schema = null): array
    {
        [$appLabel, $modelName] = explode('.', $modelArg, 2);
        $tableName = $schema ? "{$schema}.{$this->modelToTable($modelName)}" : $this->modelToTable($modelName);
        $pk = $this->getPrimaryKey($pdo, $tableName);

        $sql = "SELECT * FROM {$this->quoteIdentifier($tableName)}";
        
        // Handle --pks filter
        if ($this->pks) {
            $pkList = explode(',', $this->pks);
            $placeholders = array_map(fn($i) => ":pk$i", array_keys($pkList));
            $sql .= " WHERE {$this->quoteIdentifier($pk)} IN (" . implode(',', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            $params = [];
            foreach ($pkList as $i => $pkValue) {
                $params[":pk$i"] = trim($pkValue);
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->query($sql . " ORDER BY " . ($this->naturalSort ? $this->quoteIdentifier($pk) : '1'));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $records = [];
        foreach ($rows as $row) {
            $record = [
                'pk' => $row[$pk],
                'model' => "{$appLabel}.{$modelName}",
                'fields' => $this->convertFields($row, $appLabel . '.' . $modelName),
            ];
            
            // Natural key support
            if ($this->naturalPrimary && $this->hasNaturalKey($pdo, $tableName)) {
                unset($record['pk']);
                $record['fields'] = $this->applyNaturalKeys($row, $tableName, $appLabel . '.' . $modelName);
            }
            
            $records[] = $record;
        }

        return $records;
    }

    private function hasNaturalKey(\PDO $pdo, string $tableName): bool
    {
        // Check if table has a unique constraint that's not the primary key
        // For simplicity, we return false - actual implementation would check schema
        return false;
    }

    private function applyNaturalKeys(array $row, string $tableName, string $model): array
    {
        // This would require inspecting the model for unique fields
        // Simplified implementation
        return $this->convertFields($row, $model);
    }

    private function dumpApp(\PDO $pdo, string $appLabel, ?string $schema = null): array
    {
        $introspector = new Introspector($pdo, $schema);
        $tables = $introspector->getTables();
        
        $records = [];
        foreach ($tables as $table) {
            if ($table->name === 'nudelsalat_migrations') continue;
            
            $modelName = $this->tableToModel($table->name);
            $pk = $this->getPrimaryKey($pdo, $table->name);
            
            $stmt = $pdo->query("SELECT * FROM {$this->quoteIdentifier($table->name)} ORDER BY " . ($this->naturalSort ? $this->quoteIdentifier($pk) : '1'));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $records[] = [
                    'pk' => $row[$pk],
                    'model' => "{$appLabel}.{$modelName}",
                    'fields' => $this->convertFields($row, "{$appLabel}.{$modelName}"),
                ];
            }
        }

        return $records;
    }

    private function dumpAllModels(\PDO $pdo, ?string $schema = null): array
    {
        $introspector = new Introspector($pdo, $schema);
        $tables = $introspector->getTables();
        
        $records = [];
        foreach ($tables as $table) {
            if ($table->name === 'nudelsalat_migrations') continue;
            
            $appLabel = 'core';
            $modelName = $this->tableToModel($table->name);
            
            if (in_array("{$appLabel}.{$modelName}", $this->excludeModels) || 
                in_array($modelName, $this->excludeModels)) {
                continue;
            }
            
            $pk = $this->getPrimaryKey($pdo, $table->name);
            
            $stmt = $pdo->query("SELECT * FROM {$this->quoteIdentifier($table->name)} ORDER BY " . ($this->naturalSort ? $this->quoteIdentifier($pk) : '1'));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $records[] = [
                    'pk' => $row[$pk],
                    'model' => "{$appLabel}.{$modelName}",
                    'fields' => $this->convertFields($row, "{$appLabel}.{$modelName}"),
                ];
            }
        }

        return $records;
    }

    private function convertFields(array $row, string $model): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = null; // Keep null as null for JSON
            }
        }
        return $row;
    }

    private function formatOutput(array $data): string
    {
        if (empty($data)) return "[]";

        $flags = JSON_UNESCAPED_SLASHES;
        if ($this->indent === true) {
            $flags |= JSON_PRETTY_PRINT;
        }

        // Handle specific indent level
        if (is_int($this->indent) && $this->indent > 0) {
            return json_encode($data, $flags | JSON_PRETTY_PRINT, $this->indent);
        }

        return match($this->format) {
            'json' => json_encode($data, $flags),
            'xml' => $this->toXml($data),
            'python' => $this->toPython($data),
            default => json_encode($data, $flags),
        };
    }

    private function toXml(array $data): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<django-objects version=\"1.0\">\n";
        foreach ($data as $record) {
            $model = htmlspecialchars($record['model']);
            $pk = htmlspecialchars((string)$record['pk']);
            $xml .= "  <object model=\"{$model}\" pk=\"{$pk}\">\n";
            foreach ($record['fields'] as $name => $value) {
                $value = htmlspecialchars($value ?? 'null');
                $xml .= "    <field name=\"" . htmlspecialchars($name) . "\">{$value}</field>\n";
            }
            $xml .= "  </object>\n";
        }
        $xml .= "</django-objects>";
        return $xml;
    }

    private function toPython(array $data): string
    {
        $output = "from django.core import serialization\n\n";
        $output .= "data = " . var_export($data, true) . "\n";
        $output .= "\n# To serialize:\n";
        $output .= "# serialization.serialize('json', data)\n";
        return $output;
    }

    private function modelToTable(string $modelName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName));
    }

    private function tableToModel(string $tableName): string
    {
        return ucfirst(preg_replace('/_(.?)/e', "strtoupper('$1')", $tableName));
    }

    private function getPrimaryKey(\PDO $pdo, string $tableName): string
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info({$this->quoteIdentifier($tableName)})");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['pk'] == 1) return $row['name'];
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$tableName}' AND column_default LIKE '%nextval%'");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? $row['column_name'] : 'id';
        } else {
            $stmt = $pdo->query("DESCRIBE {$this->quoteIdentifier($tableName)}");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['Key'] === 'PRI') return $row['Field'];
            }
        }
        
        return 'id';
    }

    private function resolveOutputPath(string $path, $config): string
    {
        if (str_starts_with($path, '/')) return $path;
        return $config->getBaseDir() . '/' . $path;
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}