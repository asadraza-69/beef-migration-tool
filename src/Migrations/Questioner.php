<?php

namespace Nudelsalat\Migrations;

class Questioner
{
    private \Nudelsalat\Console\ConsoleWriter $writer;

    public function __construct()
    {
        $this->writer = new \Nudelsalat\Console\ConsoleWriter();
    }

    public function askRenameModel(string $oldName, string $newName): bool
    {
        return $this->writer->confirm("Did you rename the model '{$oldName}' to '{$newName}'?");
    }

    public function askRenameField(string $modelName, string $oldName, string $newName): bool
    {
        return $this->writer->confirm("Did you rename the field '{$oldName}' on '{$modelName}' to '{$newName}'?");
    }

    public function askDefault(string $modelName, string $fieldName): mixed
    {
        $this->writer->warning("You are adding a non-nullable field '{$fieldName}' on '{$modelName}' without a default.");
        $this->writer->write("Please provide a one-off default value now (e.g. 'null', 0, 'now')\n");
        
        $val = $this->writer->ask("Enter value");
        
        // Handle basic type casting
        if (is_numeric($val)) return (int)$val;
        if ($val === 'true') return true;
        if ($val === 'false') return false;
        if (in_array($val, ['null', 'none', 'NULL', 'NONE'], true)) return null;
        
        return $val;
    }
}
