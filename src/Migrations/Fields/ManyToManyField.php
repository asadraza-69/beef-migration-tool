<?php

namespace Nudelsalat\Migrations\Fields;

/**
 * ManyToManyField - Many-to-many relationship field
 * 
 * Creates an intermediate junction table for many-to-many relationships.
 * 
 * Options (via $options array):
 * - through: Custom intermediate model class
 * - through_fields: Fields in the through model
 * - related_name: Reverse relation name
 * - db_table: Custom junction table name
 * 
 * Usage:
 * ```php
 * class User extends Model
 * {
 *     public static function fields(): array
 *     {
 *         return [
 *             'id' => new AutoField(),
 *             'name' => new StringField(100),
 *             'groups' => new ManyToManyField(Group::class),
 *         ];
 *     }
 * }
 * 
 * // Usage
 * \$user = User::get(1);
 * \$groups = \$user->groups;  // Get related groups
 * \$user->groups->add(\$group);  // Add relation
 * \$user->groups->remove(\$group);  // Remove relation
 * \$user->groups->clear();  // Clear all relations
 * ```
 */
class ManyToManyField extends Field
{
    /** @var string|null Custom intermediate model */
    public ?string $through = null;
    
    /** @var array|null Fields in through model */
    public ?array $throughFields = null;

    public function __construct(
        public string $to,
        public ?string $relatedName = null,
        public ?string $dbTable = null,
        array $options = []
    ) {
        $this->through = $options['through'] ?? null;
        $this->throughFields = $options['through_fields'] ?? null;
        parent::__construct(true, null, false, false, $options);
    }

    /**
     * Get the SQL type.
     * 
     * ManyToMany fields do not have a column in the source table.
     * 
     * @return string
     */
    public function getType(): string
    {
        return 'virtual';
    }

    /**
     * Get the intermediate table name.
     * 
     * @param string $modelClass The source model class
     * @return string
     */
    public function getDbTable(string $modelClass): string
    {
        if ($this->dbTable) {
            return $this->dbTable;
        }
        
        $sourceName = strtolower((new \ReflectionClass($modelClass))->getShortName());
        $toName = strtolower((new \ReflectionClass($this->to))->getShortName());
        
        return "{$sourceName}_{$toName}";
    }

    /**
     * Check if this is a self-referential relationship.
     * 
     * @return bool
     */
    public function isSelfReferential(): bool
    {
        return $this->to === (new \ReflectionClass($this))->getName();
    }
}
