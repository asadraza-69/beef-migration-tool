<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class AlterField extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name,
        public Column $field,
        public ?Column $oldField = null
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->addColumn($this->field);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->alterColumn($this->modelName, $this->field);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($this->oldField) {
            $schemaEditor->alterColumn($this->modelName, $this->oldField);
        }
    }

    public function describe(): string
    {
        return "Alter field {$this->name} on {$this->modelName}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name,
                $this->field,
                $this->oldField
            ]
        ];
    }

    public function reduce(Operation $other, string $appLabel): array|bool
    {
        if ($other instanceof AlterField && $other->modelName === $this->modelName && $other->name === $this->name) {
            return [
                new AlterField($this->modelName, $this->name, $other->field, $this->oldField)
            ];
        }

        if ($other instanceof RemoveField && $other->modelName === $this->modelName && $other->name === $this->name) {
            return [
                $other
            ];
        }

        if ($other instanceof DeleteModel && $other->name === $this->modelName) {
            return [
                $other
            ];
        }

        if (
            property_exists($other, 'modelName') && $other->modelName === $this->modelName &&
            ((property_exists($other, 'name') && $other->name === $this->name) || 
             (property_exists($other, 'oldName') && $other->oldName === $this->name))
        ) {
            return false;
        }

        return true;
    }
}
