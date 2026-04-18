<?php

namespace Nudelsalat\Migrations\Fields;

/**
 * ForeignKey - One-to-many relationship field
 * 
 * Creates a foreign key constraint referencing another model.
 * 
 * Options (via $options array):
 * - onDelete: CASCADE, SET_NULL, SET_DEFAULT, PROTECT, RESTRICT, NO_ACTION
 * - relatedName: Reverse relation name (default: model_lower_set)
 * - db_index: Create index on the column
 * - unique: Make this FK unique
 * 
 * @property string $to Target model class name
 * @property string $onDelete Delete behavior
 * @property string|null $relatedName Reverse relation name
 */
class ForeignKey extends Field
{
    public function __construct(
        public string $to,
        public string $onDelete = 'CASCADE',
        public ?string $relatedName = null,
        public ?string $toField = null,
        public ?string $dbConstraint = null,
        bool $nullable = false,
        mixed $default = null,
        array $options = []
    ) {
        parent::__construct($nullable, $default, false, false, $options);
    }

    public function getType(): string
    {
        // Foreign keys usually share the type of the primary key they point to.
        // For simplicity in Beef, we'll assume integer IDs.
        return 'int';
    }

    /**
     * Check if this FK should have an index.
     * 
     * @return bool
     */
    public function isIndexed(): bool
    {
        return $this->options['db_index'] ?? $this->options['index'] ?? true;
    }

    /**
     * Check if this FK should be unique.
     * 
     * @return bool
     */
    public function isUnique(): bool
    {
        return $this->options['unique'] ?? false;
    }
}

/**
 * OneToOneField - One-to-one relationship field
 * 
 * Same as ForeignKey but enforces unique constraint (only one related object).
 * Typically used for UserProfile, UserSettings, etc.
 * 
 * @property string $to Target model class name
 * @property string $onDelete Delete behavior
 * @property string|null $relatedName Reverse relation name
 */
class OneToOneField extends ForeignKey
{
    public function __construct(
        public string $to,
        public string $onDelete = 'CASCADE',
        public ?string $relatedName = null,
        public ?string $toField = null,
        bool $nullable = false,
        mixed $default = null,
        array $options = []
    ) {
        // Force unique by default
        $options['unique'] = $options['unique'] ?? true;
        parent::__construct($to, $onDelete, $relatedName, $toField, null, $nullable, $default, $options);
    }

    /**
     * OneToOneField is always unique.
     * 
     * @return true
     */
    public function isUnique(): bool
    {
        return true;
    }
}

/**
 * ManyToManyField - Many-to-many relationship field
 * 
 * Creates a junction table automatically for many-to-many relationships.
 * 
 * Options (via $options array):
 * - through: Custom intermediate model
 * - relatedName: Reverse relation name
 * - symmetrical: For self-referential relationships
 * - db_table: Custom junction table name
 * 
 * @property string $to Target model class name
 * @property string|null $through Custom intermediate model
 * @property string|null $relatedName Reverse relation name
 */
class ManyToManyField extends Field
{
    public function __construct(
        public string $to,
        public ?string $through = null,
        public ?string $throughFields = null,
        public ?string $relatedName = null,
        public ?string $dbTable = null,
        public ?string $dbConstraint = null,
        public ?string $symmetrical = null,
        array $options = []
    ) {
        parent::__construct(false, null, false, false, $options);
    }

    public function getType(): string
    {
        return 'many_to_many';
    }
}
