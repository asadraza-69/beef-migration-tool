<?php

namespace Nudelsalat\Migrations;

class MigrationWriter
{
    public function __construct(
        public array $operations,
        public array $dependencies,
        public string $migrationName,
        public array $replaces = [],
        public ?bool $initial = null,
        public bool $atomic = true,
        public array $runBefore = []
    ) {}

    public function asPhp(): string
    {
        $opsStr = $this->serializeOperations();
        $depsStr = var_export($this->dependencies, true);
        $imports = $this->buildImportBlock();

        $replacesStr = '';
        if (!empty($this->replaces)) {
            $replacesStr = "\n        \$this->replaces = " . var_export($this->replaces, true) . ";";
        }

        $runBeforeStr = '';
        if (!empty($this->runBefore)) {
            $runBeforeStr = "\n        \$this->runBefore = " . var_export($this->runBefore, true) . ";";
        }

        return <<<PHP
<?php

{$imports}


return new class('{$this->migrationName}') extends \Nudelsalat\Migrations\Migration {
    public function __construct(string \$name)
    {
        parent::__construct(\$name);
        \$this->dependencies = {$depsStr};{$replacesStr}
        \$this->initial = {$this->initialString()};
        \$this->atomic = {$this->atomicString()};{$runBeforeStr}
        \$this->operations = [
{$opsStr}
        ];
    }
};
PHP;
    }

    private function serializeOperations(): string
    {
        $serialized = [];
        foreach ($this->operations as $op) {
            $serialized[] = "        " . trim($this->serializeObject($op));
        }
        return implode(",\n\n", $serialized);
    }

    private function serializeObject(mixed $obj, int $indent = 8): string
    {
        if (is_array($obj)) {
            return $this->serializeArray($obj, $indent);
        }

        if (is_object($obj) && method_exists($obj, 'deconstruct')) {
            [$class, $args] = $obj->deconstruct();
            
            // Short class name for serialization
            $parts = explode('\\', $class);
            $className = end($parts);
            
            $serializedArgs = [];
            foreach ($args as $key => $arg) {
                $argStr = $this->serializeObject($arg, $indent + 4);
                $serializedArgs[] = $argStr;
            }
            
            $argsStr = implode(", ", $serializedArgs);
            if (strlen($argsStr) > 60) {
               $argsStr = "\n" . str_repeat(' ', $indent + 4) . 
                          implode(",\n" . str_repeat(' ', $indent + 4), $serializedArgs) . 
                          "\n" . str_repeat(' ', $indent);
            }

            return "new {$className}({$argsStr})";
        }

        if (is_string($obj)) {
            return "'" . $this->quote($obj) . "'";
        }

        return var_export($obj, true);
    }

    private function buildImportBlock(): string
    {
        $imports = [];

        foreach ($this->operations as $operation) {
            $this->collectImports($operation, $imports);
        }

        sort($imports);

        return implode("\n", array_map(static fn(string $class): string => "use {$class};", array_unique($imports)));
    }

    private function collectImports(mixed $value, array &$imports): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectImports($item, $imports);
            }
            return;
        }

        if (!is_object($value) || !method_exists($value, 'deconstruct')) {
            return;
        }

        [$class, $args] = $value->deconstruct();
        $imports[] = $class;

        foreach ($args as $arg) {
            $this->collectImports($arg, $imports);
        }
    }

    private function serializeArray(array $data, int $indent): string
    {
        if (empty($data)) return "[]";

        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        $items = [];
        foreach ($data as $key => $value) {
            $valStr = $this->serializeObject($value, $indent + 4);
            if ($isAssoc) {
                $items[] = "'{$key}' => {$valStr}";
            } else {
                $items[] = $valStr;
            }
        }

        return "[\n" . str_repeat(' ', $indent + 4) . 
               implode(",\n" . str_repeat(' ', $indent + 4), $items) . 
               "\n" . str_repeat(' ', $indent) . "]";
    }

    private function quote(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function atomicString(): string
    {
        return $this->atomic ? 'true' : 'false';
    }

    private function initialString(): string
    {
        if ($this->initial === null) {
            return 'false';
        }

        return $this->initial ? 'true' : 'false';
    }
}
