<?php

namespace Nudelsalat\Migrations\Fields;

abstract class Field
{
    public function __construct(
        public bool $nullable = false,
        public mixed $default = null,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public array $options = []
    ) {}

    abstract public function getType(): string;

    public function isUnique(): bool
    {
        return $this->options['unique'] ?? false;
    }

    public function isIndexed(): bool
    {
        return $this->options['db_index'] ?? $this->options['index'] ?? false;
    }

    public function getChoices(): ?array
    {
        return $this->options['choices'] ?? null;
    }

    public function getHelpText(): ?string
    {
        return $this->options['help_text'] ?? null;
    }

    public function getVerboseName(): ?string
    {
        return $this->options['verbose_name'] ?? null;
    }

    public function deconstruct(): array
    {
        $args = [
            'nullable' => $this->nullable,
            'default' => $this->default,
            'primary_key' => $this->primaryKey,
            'auto_increment' => $this->autoIncrement,
        ];
        
        // Add options that are set
        foreach (['unique', 'db_index', 'index', 'choices', 'help_text', 'verbose_name'] as $key) {
            if (isset($this->options[$key])) {
                $args[$key] = $this->options[$key];
            }
        }
        
        return [get_class($this), $args];
    }
}

class IntField extends Field
{
    public function getType(): string
    {
        return 'int';
    }
}

// Auto fields - for primary keys that auto-increment
class AutoField extends IntField
{
    public function getType(): string
    {
        return 'serial';
    }
}

class BigAutoField extends IntField
{
    public function getType(): string
    {
        return 'bigserial';
    }
}

class SmallAutoField extends IntField
{
    public function getType(): string
    {
        return 'smallserial';
    }
}

// Positive numeric fields
class PositiveIntegerField extends IntField
{
    public function getType(): string
    {
        return 'integer';
    }
}

class PositiveSmallIntegerField extends IntField
{
    public function getType(): string
    {
        return 'smallint';
    }
}

class PositiveBigIntegerField extends IntField
{
    public function getType(): string
    {
        return 'bigint';
    }
}

class StringField extends Field
{
    public function __construct(
        public int $length = 255,
        bool $nullable = false,
        mixed $default = null,
        bool $primaryKey = false,
        bool $autoIncrement = false,
        array $options = []
    ) {
        parent::__construct($nullable, $default, $primaryKey, $autoIncrement, $options);
    }

    public function getType(): string
    {
        return 'varchar(' . $this->length . ')';
    }

    public function deconstruct(): array
    {
        [$class, $args] = parent::deconstruct();
        $args['length'] = $this->length;
        return [$class, $args];
    }
}

class TextField extends Field
{
    public function getType(): string
    {
        return 'text';
    }
}

class BooleanField extends Field
{
    public function getType(): string
    {
        return 'boolean';
    }
}

class DecimalField extends Field
{
    public function __construct(
        public int $maxDigits = 19,
        public int $decimalPlaces = 4,
        bool $nullable = false,
        mixed $default = null,
        bool $primaryKey = false,
        bool $autoIncrement = false,
        array $options = []
    ) {
        parent::__construct($nullable, $default, $primaryKey, $autoIncrement, $options);
    }

    public function getType(): string
    {
        return 'decimal';
    }

    public function deconstruct(): array
    {
        [$class, $args] = parent::deconstruct();
        $args['max_digits'] = $this->maxDigits;
        $args['decimal_places'] = $this->decimalPlaces;
        return [$class, $args];
    }
}

class DateTimeField extends Field
{
    public function getType(): string
    {
        return 'datetime';
    }
}

class DateField extends Field
{
    public function getType(): string
    {
        return 'date';
    }
}

class TimeField extends Field
{
    public function getType(): string
    {
        return 'time';
    }
}

class FloatField extends Field
{
    public function getType(): string
    {
        return 'float';
    }
}

class UUIDField extends Field
{
    public function getType(): string
    {
        return 'uuid';
    }
}

class SlugField extends StringField
{
    public function getType(): string
    {
        return 'varchar(50)';
    }
}

class EmailField extends StringField
{
    public function getType(): string
    {
        return 'varchar(254)';
    }
}

class URLField extends StringField
{
    public function getType(): string
    {
        return 'varchar(200)';
    }
}

class BinaryField extends Field
{
    public function getType(): string
    {
        return 'bytea';
    }
}

class JSONField extends Field
{
    public function getType(): string
    {
        return 'jsonb';
    }
}
