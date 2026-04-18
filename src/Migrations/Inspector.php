<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\Index;
use Nudelsalat\Schema\Table;
use Nudelsalat\ORM\Model;

class Inspector
{
    /**
     * @param string[] $directories Array of [appName => directory]
     * @return ProjectState
     */
    public function inspect(array $directories): ProjectState
    {
        $tables = [];
        $directoryRoots = [];
        
        foreach ($directories as $appName => $entry) {
            $directory = is_array($entry) ? ($entry['models'] ?? null) : $entry;
            if (!is_dir($directory)) continue;
            $directoryRoots[$appName] = realpath($directory) ?: $directory;
            
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            
            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                require_once $file->getPathname();
            }
        }

        foreach (get_declared_classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $fileName = $reflection->getFileName();
            if (!$fileName) {
                continue;
            }

            $matchedApp = $this->matchAppForFile($fileName, $directoryRoots);
            if ($matchedApp === null) {
                continue;
            }

            if (is_subclass_of($class, Model::class) && !$reflection->isAbstract()) {
                // Validate source for duplicate keys
                $this->validateSourceFile($fileName, $class);
                
                // Get Model Options
                $options = property_exists($class, 'options') ? $class::$options : [];
                $options['app_label'] = $matchedApp;
                $matchedEntry = $directories[$matchedApp];
                if (is_array($matchedEntry) && isset($matchedEntry['database'])) {
                    $options['database'] = $matchedEntry['database'];
                }
                
                // 1. Respect 'managed'
                if (isset($options['managed']) && $options['managed'] === false) {
                    continue;
                }

                // 2. Custom Table Name
                $tableName = isset($options['db_table']) ? $options['db_table'] : $class::getTableName();
                
                if (isset($tables[$tableName])) continue;

                $fields = $class::fields();
                
                $this->validateFields($fields, $class, $tableName);
                
                $columns = [];
                $indexes = $this->normalizeIndexes($options['indexes'] ?? [], $tableName);
                $constraints = $this->normalizeConstraints($options['constraints'] ?? [], $tableName);
                $fks = [];
                foreach ($fields as $name => $field) {
                    if ($field instanceof \Nudelsalat\Migrations\Fields\ForeignKey) {
                        // Add the ID column
                        $colName = $name . "_id";
                        $columns[] = new Column(
                            $colName,
                            'int',
                            $field->nullable,
                            $field->default,
                            false,
                            false,
                            $field->options
                        );
                        // Add FK metadata
                        $fks[] = new \Nudelsalat\Schema\ForeignKeyConstraint(
                            "fk_{$tableName}_{$colName}",
                            $colName,
                            $field->to,
                            "id", // Assume 'id' for simplicity
                            $field->onDelete
                        );

                        if (($field->options['db_index'] ?? $field->options['index'] ?? true) === true) {
                            $indexes[] = new Index("idx_{$tableName}_{$colName}", [$colName]);
                        }
                        continue;
                    }

                    if ($field instanceof \Nudelsalat\Migrations\Fields\ManyToManyField) {
                        // Store M2M in options for Autodetector
                        $options['m2m'][$name] = [
                            'to' => $field->to,
                            'db_table' => $field->dbTable,
                        ];
                        continue;
                    }

                    $columns[] = new Column(
                        $name,
                        $field->getType(),
                        $field->nullable,
                        $field->default,
                        $field->primaryKey,
                        $field->autoIncrement,
                        $field->options
                    );

                    if (($field->options['unique'] ?? false) === true) {
                        $constraints[] = new Constraint("uniq_{$tableName}_{$name}", 'unique', [$name]);
                    }

                    if (($field->options['db_index'] ?? $field->options['index'] ?? false) === true) {
                        $indexes[] = new Index("idx_{$tableName}_{$name}", [$name]);
                    }
                }

                // 3. Respect 'proxy' (passed in options)
                $tables[$tableName] = new Table($tableName, $columns, $options, $indexes, $constraints, $fks);
            }
        }

        return new ProjectState(array_values($tables));
    }

    private function validateFields(array $fields, string $class, string $tableName): void
    {
        return;
    }

    public function validateSourceFile(string $filePath, string $class): void
    {
        $content = file_get_contents($filePath);
        
        // Extract only the fields() method content
        if (preg_match('/function fields\(\):\s*array\s*\{(.*?)\n    \}/s', $content, $matches)) {
            $fieldsContent = $matches[1];
            
            if (preg_match_all('/[\'"](\w+)[\'"]\s*=>/', $fieldsContent, $matches, PREG_OFFSET_CAPTURE)) {
                $fieldNames = [];
                foreach ($matches[1] as [$name, $offset]) {
                    if (isset($fieldNames[$name])) {
                        $shortClass = (new \ReflectionClass($class))->getShortName();
                        throw new \RuntimeException(
                            "Duplicate field '{$name}' in {$shortClass}::fields(). " .
                            "PHP arrays cannot have duplicate keys."
                        );
                    }
                    $fieldNames[$name] = true;
                }
            }
        }
    }

    private function matchAppForFile(string $fileName, array $directoryRoots): ?string
    {
        $realPath = realpath($fileName) ?: $fileName;

        foreach ($directoryRoots as $appName => $root) {
            if (str_starts_with($realPath, $root . DIRECTORY_SEPARATOR) || $realPath === $root) {
                return $appName;
            }
        }

        return null;
    }

    /** @return Index[] */
    private function normalizeIndexes(array $definitions, string $tableName): array
    {
        $indexes = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof Index) {
                $indexes[] = $definition;
                continue;
            }

            if (is_array($definition)) {
                $indexes[] = new Index(
                    $definition['name'] ?? ('idx_' . $tableName . '_' . implode('_', $definition['columns'] ?? [])),
                    $definition['columns'] ?? [],
                    (bool) ($definition['unique'] ?? false)
                );
            }
        }

        return $indexes;
    }

    /** @return Constraint[] */
    private function normalizeConstraints(array $definitions, string $tableName): array
    {
        $constraints = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof Constraint) {
                $constraints[] = $definition;
                continue;
            }

            if (is_array($definition)) {
                $constraints[] = new Constraint(
                    $definition['name'] ?? ('constraint_' . $tableName),
                    $definition['type'] ?? 'unique',
                    $definition['columns'] ?? [],
                    $definition['checkSql'] ?? null
                );
            }
        }

        return $constraints;
    }
}
