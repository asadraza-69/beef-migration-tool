<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class AddField extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name,
        public Column $field
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
        $schemaEditor->addColumn($this->modelName, $this->field);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->removeColumn($this->modelName, $this->name);
    }

    public function describe(): string
    {
        return "Add field {$this->name} to {$this->modelName}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name,
                $this->field
            ]
        ];
    }

    public function reduce(Operation $other, string $appLabel): array|bool
    {
        if ($other instanceof AlterField && $other->modelName === $this->modelName && $other->name === $this->name) {
            return [
                new AddField($this->modelName, $this->name, $other->field)
            ];
        }

        if ($other instanceof RemoveField && $other->modelName === $this->modelName && $other->name === $this->name) {
            return [];
        }

        if ($other instanceof RenameField && $other->modelName === $this->modelName && $other->oldName === $this->name) {
            $field = clone $this->field;
            $field->name = $other->newName;
            return [
                new AddField($this->modelName, $other->newName, $field)
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
