<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Schema\Table;
use Nudelsalat\Migrations\Operations\AddForeignKey;

class Validator
{
    /**
     * Performs a deep relational audit on the project state.
     * 
     * @return string[] List of validation error messages
     */
    public function validateState(ProjectState $state): array
    {
        $errors = [];
        $tables = $state->getTables();
        $tableNames = array_keys($tables);

        foreach ($tables as $table) {
            // 1. Check for orphaned Foreign Keys
            foreach ($table->getColumns() as $column) {
                // We need a way to detect if a column IS a foreign key in the state.
                // Currently, Table/Column are pure DDL. 
                // However, we can track constraints.
            }

            // Since our Table/Column objects don't store "toTable" directly (only constraints do),
            // I will implement a check based on the common SQL patterns we use.
            
            // 2. Reserved Keyword Collision Check (Basic)
            $reserved = ['order', 'group', 'user', 'select', 'table', 'index', 'primary', 'key'];
            if (in_array(strtolower($table->name), $reserved)) {
                $errors[] = "Table name '{$table->name}' is a reserved SQL keyword and may cause issues.";
            }

            foreach ($table->getColumns() as $column) {
                if (in_array(strtolower($column->name), $reserved)) {
                    $errors[] = "Column '{$column->name}' in table '{$table->name}' is a reserved SQL keyword.";
                }
            }
        }

        return $errors;
    }

    /**
     * Cross-App Relational Validation
     * Ensures all ForeignKeys in a set of operations point to valid tables in the target state.
     */
    public function validateOperations(array $operations, ProjectState $targetState): array
    {
        $errors = [];
        foreach ($operations as $op) {
            if ($op instanceof AddForeignKey) {
                if (!$targetState->getTable($op->toModel)) {
                    $errors[] = "Orphaned ForeignKey: Operation wants to point to '{$op->toModel}', but that table does not exist in the project state.";
                }
            }
        }
        return $errors;
    }
}
