<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Migrations\Operations\AddConstraint;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Migrations\Operations\AddIndex;
use Nudelsalat\Migrations\Operations\AlterField;
use Nudelsalat\Migrations\Operations\AlterModelManagers;
use Nudelsalat\Migrations\Operations\AlterModelOptions;
use Nudelsalat\Migrations\Operations\AlterModelTable;
use Nudelsalat\Migrations\Operations\AlterModelTableComment;
use Nudelsalat\Migrations\Operations\AlterUniqueTogether;
use Nudelsalat\Migrations\Operations\AlterIndexTogether;
use Nudelsalat\Migrations\Operations\AlterOrderWithRespectTo;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\DeleteModel;
use Nudelsalat\Migrations\Operations\RemoveConstraint;
use Nudelsalat\Migrations\Operations\RemoveField;
use Nudelsalat\Migrations\Operations\RemoveForeignKey;
use Nudelsalat\Migrations\Operations\RemoveIndex;
use Nudelsalat\Migrations\Operations\RenameField;
use Nudelsalat\Migrations\Operations\RenameModel;
use Nudelsalat\Schema\Column;

class Autodetector
{
    private ProjectState $fromState;
    private ProjectState $toState;
    private Questioner $questioner;
    private Optimizer $optimizer;

    public function __construct(ProjectState $fromState, ProjectState $toState, ?Questioner $questioner = null)
    {
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->questioner = $questioner ?: new Questioner();
        $this->optimizer = new Optimizer();
    }

    /**
     * @return \Nudelsalat\Migrations\Operations\Operation[]
     */
    public function detectChanges(): array
    {
        $operations = [];

        $oldTables = $this->fromState->getTables();
        $newTables = $this->toState->getTables();

        // 1. Detect Model Renames
        $renamedModels = [];
        $addedModels = array_diff(array_keys($newTables), array_keys($oldTables));
        $removedModels = array_diff(array_keys($oldTables), array_keys($newTables));

        foreach ($addedModels as $newName) {
            foreach ($removedModels as $oldName) {
                if ($this->isTableSimilar($oldTables[$oldName], $newTables[$newName])) {
                    if ($this->questioner->askRenameModel($oldName, $newName)) {
                        $operations[] = new RenameModel($oldName, $newName);
                        $renamedModels[$newName] = $oldName;
                        unset($removedModels[array_search($oldName, $removedModels, true)]);
                        unset($addedModels[array_search($newName, $addedModels, true)]);
                        break;
                    }
                }
            }
        }

        // 2. Detect Created Models
        $createModelOps = [];
        $fkOps = [];
        
        foreach ($addedModels as $name) {
            $table = $newTables[$name];
            if (isset($table->options['proxy']) && $table->options['proxy'] === true) continue;

            $createModelOps[$name] = new CreateModel($name, $table->getColumns(), $table->options);

            foreach ($table->getIndexes() as $index) {
                $fkOps[] = new AddIndex($name, $index);
            }

            foreach ($table->getConstraints() as $constraint) {
                $fkOps[] = new AddConstraint($name, $constraint);
            }
            
            // Add Foreign Keys for the new model
            foreach ($table->getForeignKeys() as $fk) {
                $fkOps[] = new AddForeignKey($name, $fk->name, $fk->column, $fk->toTable, $fk->toColumn, $fk->onDelete);
            }

            // Handle ManyToMany Junction Tables
            if (isset($table->options['m2m'])) {
                foreach ($table->options['m2m'] as $fieldName => $m2m) {
                    $fkOps = array_merge(
                        $fkOps,
                        $this->generateM2MJunction($name, $fieldName, $m2m, $table->options['app_label'] ?? null)
                    );
                }
            }
        }

        // Create all tables first, then apply indexes/constraints/FKs. This avoids ordering issues
        // when a table references another newly-created table in the same migration.
        $orderedCreateModelOps = array_values($createModelOps);
        
        foreach ($orderedCreateModelOps as $op) {
            $operations[] = $op;
        }
        foreach ($fkOps as $op) {
            $operations[] = $op;
        }

        // 3. Detect Deleted Models
        foreach ($removedModels as $name) {
            $table = $oldTables[$name];
            if (isset($table->options['proxy']) && $table->options['proxy'] === true) continue;
            $operations[] = new DeleteModel($name);
        }

        // 4. Detect Field Changes
        foreach ($newTables as $name => $newTable) {
            if (isset($newTable->options['proxy']) && $newTable->options['proxy'] === true) continue;

            $oldName = $renamedModels[$name] ?? (isset($oldTables[$name]) ? $name : null);
            if ($oldName === null) continue;

            $oldTable = $oldTables[$oldName];
            $oldColumns = $oldTable->getColumns();
            $newColumns = $newTable->getColumns();
            $tableOperations = [];
            $preFieldOperations = [];
            $postFieldOperations = [];

            // Check if model was renamed
            $modelRenamed = isset($renamedModels[$name]);
            $metadataOperations = $this->detectModelOptionChanges($name, $oldTable, $newTable, $modelRenamed);
            $operations = array_merge($operations, $metadataOperations['pre']);

            $addedFields = array_diff(array_keys($newColumns), array_keys($oldColumns));
            $removedFields = array_diff(array_keys($oldColumns), array_keys($newColumns));
            $keptFields = array_intersect(array_keys($oldColumns), array_keys($newColumns));
            $renamedFields = [];

            // Detect relational changes (FK, constraints, indexes) even when no fields changed
            // This ensures we detect changes to existing relations
            $hasFieldChanges = !empty($addedFields) || !empty($removedFields);
            
            if (!$hasFieldChanges) {
                // Check if any kept fields have changed (AlterField detection)
                $hasAlterations = false;
                foreach ($keptFields as $colName) {
                    if (!$this->isColumnIdentical($oldColumns[$colName], $newColumns[$colName], false)) {
                        $hasAlterations = true;
                        break;
                    }
                }

                // Always detect relation changes when no field changes
                $preFieldOperations = array_merge(
                    $preFieldOperations,
                    $this->detectRemovedAndChangedForeignKeys($name, $oldTable, $newTable, $modelRenamed ? $oldName : null),
                    $this->detectRemovedAndChangedConstraints($name, $oldTable, $newTable),
                    $this->detectRemovedAndChangedIndexes($name, $oldTable, $newTable)
                );

                // If there are also field alterations, detect those
                if ($hasAlterations) {
                    foreach ($keptFields as $colName) {
                        if (!$this->isColumnIdentical($oldColumns[$colName], $newColumns[$colName], false)) {
                            $tableOperations[] = new AlterField($name, $colName, $newColumns[$colName], $oldColumns[$colName]);
                        }
                    }
                }

                $postFieldOperations = array_merge(
                    $postFieldOperations,
                    $this->detectAddedAndChangedIndexes($name, $oldTable, $newTable),
                    $this->detectAddedAndChangedConstraints($name, $oldTable, $newTable),
                    $this->detectAddedAndChangedForeignKeys($name, $oldTable, $newTable, $modelRenamed ? $oldName : null)
                );
            } else {
                // Field changes detected - keep original logic
                foreach ($addedFields as $newFieldName) {
                    foreach ($removedFields as $removedKey => $oldFieldName) {
                        if (!$this->isColumnRenameCandidate($oldColumns[$oldFieldName], $newColumns[$newFieldName])) {
                            continue;
                        }

                        if ($this->questioner->askRenameField($name, $oldFieldName, $newFieldName)) {
                            $tableOperations[] = new RenameField($name, $oldFieldName, $newFieldName);
                            unset($removedFields[$removedKey]);

                            $addedKey = array_search($newFieldName, $addedFields, true);
                            if ($addedKey !== false) {
                                unset($addedFields[$addedKey]);
                            }

                            $renamedFields[$newFieldName] = $oldFieldName;
                            $keptFields[] = $newFieldName;
                            break;
                        }
                    }
                }

                $keptFields = array_values(array_unique($keptFields));

                foreach ($keptFields as $colName) {
                    $oldColumnName = $renamedFields[$colName] ?? $colName;
                    if (!$this->isColumnIdentical($oldColumns[$oldColumnName], $newColumns[$colName], isset($renamedFields[$colName]))) {
                        $tableOperations[] = new AlterField($name, $colName, $newColumns[$colName], $oldColumns[$oldColumnName]);
                    }
                }

                $preFieldOperations = array_merge(
                    $preFieldOperations,
                    $this->detectRemovedAndChangedForeignKeys($name, $oldTable, $newTable, $modelRenamed ? $oldName : null),
                    $this->detectRemovedAndChangedConstraints($name, $oldTable, $newTable),
                    $this->detectRemovedAndChangedIndexes($name, $oldTable, $newTable)
                );

                foreach ($addedFields as $colName) {
                    $column = $newColumns[$colName];
                    
                    // If the field is nullable OR has explicit default, don't ask
                    // Only ask for NOT NULL fields without default
                    if (!$column->nullable && $column->default === null && !$column->autoIncrement) {
                        $column->default = $this->questioner->askDefault($name, $colName);
                    }

                    $tableOperations[] = new AddField($name, $colName, $column);
                }

                foreach ($removedFields as $colName) {
                    $tableOperations[] = new RemoveField($name, $colName);
                }

                $postFieldOperations = array_merge(
                    $postFieldOperations,
                    $this->detectAddedAndChangedIndexes($name, $oldTable, $newTable),
                    $this->detectAddedAndChangedConstraints($name, $oldTable, $newTable),
                    $this->detectAddedAndChangedForeignKeys($name, $oldTable, $newTable, $modelRenamed ? $oldName : null)
                );

                // (New M2M fields on existing models)
                if (isset($newTable->options['m2m'])) {
                    $oldM2m = $oldTable->options['m2m'] ?? [];
                    $newM2m = $newTable->options['m2m'];
                    foreach ($newTable->options['m2m'] as $fieldName => $m2m) {
                        if (!isset($oldM2m[$fieldName])) {
                            $postFieldOperations = array_merge(
                                $postFieldOperations,
                                $this->generateM2MJunction($name, $fieldName, $m2m, $newTable->options['app_label'] ?? null)
                            );
                        }
                    }

                    foreach ($oldM2m as $fieldName => $m2m) {
                        if (!isset($newM2m[$fieldName])) {
                            $junctionTable = $m2m['db_table'] ?: "{$name}_{$fieldName}";
                            $postFieldOperations[] = new DeleteModel($junctionTable);
                        }
                    }
                }
            }

            $operations = array_merge($operations, $preFieldOperations, $tableOperations, $postFieldOperations, $metadataOperations['post']);
        }

        // 5. Django-style: handle db_table changes LAST (after rename detection is complete)
        $operations = array_merge($operations, $this->generateAlteredDbTable());

        return $this->optimizer->optimize($operations);
    }

    /**
     * Generate AlterModelTable operations for db_table option changes.
     * Django-style: separate pass after all renames are handled.
     */
    private function generateAlteredDbTable(): array
    {
        $operations = [];
        $oldTables = $this->fromState->getTables();
        $newTables = $this->toState->getTables();

        // Only process models that exist in both states (kept models)
        foreach ($newTables as $name => $newTable) {
            $oldTable = $oldTables[$name] ?? null;
            if (!$oldTable) {
                continue; // Skip newly created models
            }

            $oldDbTable = $oldTable->options['db_table'] ?? null;
            $newDbTable = $newTable->options['db_table'] ?? null;

            // Simple comparison - if db_table changed, generate AlterModelTable
            if ($oldDbTable !== $newDbTable) {
                $operations[] = new AlterModelTable($name, $newDbTable);
            }
        }

        return $operations;
    }

    private function generateM2MJunction(string $modelName, string $fieldName, array $m2m, ?string $appLabel = null): array
    {
        $junctionTable = $m2m['db_table'] ?: "{$modelName}_{$fieldName}";
        $toModel = $m2m['to'];

        $columns = [
            'id' => new Column('id', 'int', false, null, true, true),
            "{$modelName}_id" => new Column("{$modelName}_id", 'int', false),
            "{$toModel}_id" => new Column("{$toModel}_id", 'int', false),
        ];

        $ops = [];
        $options = [];
        if ($appLabel !== null) {
            $options['app_label'] = $appLabel;
        }

        $ops[] = new CreateModel($junctionTable, array_values($columns), $options);
        $ops[] = new AddForeignKey($junctionTable, "fk_{$junctionTable}_{$modelName}", "{$modelName}_id", $modelName, "id");
        $ops[] = new AddForeignKey($junctionTable, "fk_{$junctionTable}_{$toModel}", "{$toModel}_id", $toModel, "id");
        $ops[] = new AddIndex($junctionTable, new \Nudelsalat\Schema\Index("idx_{$junctionTable}_{$modelName}", ["{$modelName}_id"]));
        $ops[] = new AddIndex($junctionTable, new \Nudelsalat\Schema\Index("idx_{$junctionTable}_{$toModel}", ["{$toModel}_id"]));
        $ops[] = new AddConstraint(
            $junctionTable,
            new \Nudelsalat\Schema\Constraint(
                "uniq_{$junctionTable}_pair",
                'unique',
                ["{$modelName}_id", "{$toModel}_id"]
            )
        );

        return $ops;
    }

    private function isTableSimilar(\Nudelsalat\Schema\Table $old, \Nudelsalat\Schema\Table $new): bool
    {
        // If conceptual model name is the same (old->name === new->name), but db_table differs,
        // Use case-insensitive comparison for model names
        if (strtolower($old->name) === strtolower($new->name)) {
            $oldDbTable = $old->options['db_table'] ?? null;
            $newDbTable = $new->options['db_table'] ?? null;
            if ($oldDbTable !== $newDbTable) {
                // Same model name, different db_table - not a rename
                return false;
            }
        }

        // Django-style: use relation-agnostic field comparison
        // Compare columns ignoring relation targets (FK, M2M)
        $oldCols = $this->getRelationAgnosticColumns($old);
        $newCols = $this->getRelationAgnosticColumns($new);

        if (empty($oldCols) || empty($newCols)) {
            return false;
        }

        $overlap = count(array_intersect($oldCols, $newCols));
        $largest = max(count($oldCols), count($newCols));

        return $largest > 0 && ($overlap / $largest) >= 0.7;
    }

    /**
     * Get columns for comparison ignoring relation targets.
     * This follows Django's only_relation_agnostic_fields() approach.
     */
    private function getRelationAgnosticColumns(\Nudelsalat\Schema\Table $table): array
    {
        $cols = [];
        foreach ($table->getColumns() as $column) {
            // Compare by type and nullability only (ignore relation info)
            $cols[] = $column->type . ':' . ($column->nullable ? '1' : '0');
        }
        return $cols;
    }

    private function isColumnRenameCandidate(Column $old, Column $new): bool
    {
        return $this->isColumnIdentical($old, $new, true);
    }

    private function isColumnIdentical(Column $old, Column $new, bool $ignoreName = false): bool
    {
        if (!$ignoreName && $old->name !== $new->name) {
            return false;
        }

        return $old->type === $new->type
            && $old->nullable === $new->nullable
            && $old->default === $new->default
            && $old->primaryKey === $new->primaryKey
            && $old->autoIncrement === $new->autoIncrement
            && $old->options == $new->options;
    }

    private function detectRemovedAndChangedForeignKeys(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable, string $oldName = null): array
    {
        $operations = [];
        $oldForeignKeys = $oldTable->getForeignKeys();
        $newForeignKeys = $newTable->getForeignKeys();

        foreach ($oldForeignKeys as $name => $oldForeignKey) {
            if (!isset($newForeignKeys[$name])) {
                $operations[] = new RemoveForeignKey($tableName, $name);
                continue;
            }

            if (!$this->isStructurallyEqual($oldForeignKey, $newForeignKeys[$name])) {
                $operations[] = new RemoveForeignKey($tableName, $name);
            }
        }

        return $operations;
    }

    private function detectAddedAndChangedForeignKeys(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable, string $oldName = null): array
    {
        $operations = [];
        $oldForeignKeys = $oldTable->getForeignKeys();

        // If model was renamed, compute old FK name pattern to check if only name changed
        $oldTableName = $oldName ?? $tableName;
        
        foreach ($newTable->getForeignKeys() as $name => $newForeignKey) {
            // Check if this is a name-only change due to model rename
            // Old naming pattern: fk_{oldModelName}_{column}
            // New naming pattern: fk_{newModelName}_{column}
            $oldFkName = 'fk_' . $oldTableName . '_' . $newForeignKey->column;
            
            // If only the FK name changed (due to model rename), skip
            if (isset($oldForeignKeys[$oldFkName]) && $this->isStructurallyEqual($oldForeignKeys[$oldFkName], $newForeignKey)) {
                continue;
            }
            
            if (!isset($oldForeignKeys[$name]) || !$this->isStructurallyEqual($oldForeignKeys[$name], $newForeignKey)) {
                $operations[] = new AddForeignKey(
                    $tableName,
                    $newForeignKey->name,
                    $newForeignKey->column,
                    $newForeignKey->toTable,
                    $newForeignKey->toColumn,
                    $newForeignKey->onDelete
                );
            }
        }

        return $operations;
    }

    private function detectRemovedAndChangedIndexes(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable): array
    {
        $operations = [];
        $oldIndexes = $oldTable->getIndexes();
        $newIndexes = $newTable->getIndexes();

        foreach ($oldIndexes as $name => $oldIndex) {
            if (!isset($newIndexes[$name]) || !$this->isStructurallyEqual($oldIndex, $newIndexes[$name])) {
                $operations[] = new RemoveIndex($tableName, $name);
            }
        }

        return $operations;
    }

    private function detectAddedAndChangedIndexes(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable): array
    {
        $operations = [];
        $oldIndexes = $oldTable->getIndexes();

        foreach ($newTable->getIndexes() as $name => $newIndex) {
            if (!isset($oldIndexes[$name]) || !$this->isStructurallyEqual($oldIndexes[$name], $newIndex)) {
                $operations[] = new AddIndex($tableName, $newIndex);
            }
        }

        return $operations;
    }

    private function detectRemovedAndChangedConstraints(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable): array
    {
        $operations = [];
        $oldConstraints = $oldTable->getConstraints();
        $newConstraints = $newTable->getConstraints();

        foreach ($oldConstraints as $name => $oldConstraint) {
            if (!isset($newConstraints[$name]) || !$this->isStructurallyEqual($oldConstraint, $newConstraints[$name])) {
                $operations[] = new RemoveConstraint($tableName, $name);
            }
        }

        return $operations;
    }

    private function detectAddedAndChangedConstraints(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable): array
    {
        $operations = [];
        $oldConstraints = $oldTable->getConstraints();

        foreach ($newTable->getConstraints() as $name => $newConstraint) {
            if (!isset($oldConstraints[$name]) || !$this->isStructurallyEqual($oldConstraints[$name], $newConstraint)) {
                $operations[] = new AddConstraint($tableName, $newConstraint);
            }
        }

        return $operations;
    }

    private function isStructurallyEqual(object $left, object $right): bool
    {
        if (method_exists($left, 'toArray') && method_exists($right, 'toArray')) {
            return $left->toArray() == $right->toArray();
        }

        if (method_exists($left, 'deconstruct') && method_exists($right, 'deconstruct')) {
            return $left->deconstruct() == $right->deconstruct();
        }

        return $left == $right;
    }

    private function detectModelOptionChanges(string $tableName, \Nudelsalat\Schema\Table $oldTable, \Nudelsalat\Schema\Table $newTable, bool $modelRenamed = false): array
    {
        $pre = [];
        $post = [];

        $oldOptions = $oldTable->options;
        $newOptions = $newTable->options;

        $oldDbTable = $oldOptions['db_table'] ?? null;
        $newDbTable = $newOptions['db_table'] ?? null;
        
        // Handle db_table change: Django-style separate pass after renames
        // We'll handle this in a separate method called after rename detection
        // Skip here - will be handled by generateAlteredDbTable()

        $oldComment = $oldOptions['db_table_comment'] ?? null;
        $newComment = $newOptions['db_table_comment'] ?? null;
        if ($oldComment !== $newComment) {
            $post[] = new AlterModelTableComment($tableName, $newComment);
        }

        $oldUniqueTogether = $this->normalizeTupleOption($oldOptions['unique_together'] ?? []);
        $newUniqueTogether = $this->normalizeTupleOption($newOptions['unique_together'] ?? []);
        if ($oldUniqueTogether != $newUniqueTogether) {
            $post[] = new AlterUniqueTogether($tableName, $newUniqueTogether);
        }

        $oldIndexTogether = $this->normalizeTupleOption($oldOptions['index_together'] ?? []);
        $newIndexTogether = $this->normalizeTupleOption($newOptions['index_together'] ?? []);
        if ($oldIndexTogether != $newIndexTogether) {
            $post[] = new AlterIndexTogether($tableName, $newIndexTogether);
        }

        $oldOrder = $oldOptions['order_with_respect_to'] ?? null;
        $newOrder = $newOptions['order_with_respect_to'] ?? null;
        if ($oldOrder !== $newOrder) {
            $post[] = new AlterOrderWithRespectTo($tableName, $newOrder);
        }

        $oldManagers = $oldOptions['managers'] ?? [];
        $newManagers = $newOptions['managers'] ?? [];
        if ($oldManagers != $newManagers) {
            $post[] = new AlterModelManagers($tableName, $newManagers);
        }

        $oldGeneric = $this->extractAlterableModelOptions($oldOptions);
        $newGeneric = $this->extractAlterableModelOptions($newOptions);
        if ($oldGeneric != $newGeneric) {
            $post[] = new AlterModelOptions($tableName, $newGeneric);
        }

        return ['pre' => $pre, 'post' => $post];
    }

    private function normalizeTupleOption(array $value): array
    {
        $normalized = [];

        foreach ($value as $item) {
            $tuple = is_array($item) ? array_values($item) : [$item];
            $normalized[] = $tuple;
        }

        return $normalized;
    }

    private function extractAlterableModelOptions(array $options): array
    {
        $filtered = [];

        foreach (AlterModelOptions::ALTER_OPTION_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $filtered[$key] = $options[$key];
            }
        }

        ksort($filtered);

        return $filtered;
    }

}
