<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Migrations\Operations\AddIndex;
use Nudelsalat\Migrations\Operations\AddConstraint;
use Nudelsalat\Migrations\Operations\AlterField;
use Nudelsalat\Migrations\Operations\AlterConstraint;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\DeleteModel;
use Nudelsalat\Migrations\Operations\RemoveField;
use Nudelsalat\Migrations\Operations\RemoveForeignKey;
use Nudelsalat\Migrations\Operations\RemoveIndex;
use Nudelsalat\Migrations\Operations\RemoveConstraint;
use Nudelsalat\Migrations\Operations\RenameField;
use Nudelsalat\Migrations\Operations\RenameModel;
use Nudelsalat\Migrations\Operations\RenameIndex;
use Nudelsalat\Migrations\Operations\Operation;

/**
 * Migration Optimizer - Operation reduction and optimization.
 * 
 * This optimizer merges consecutive operations that can be combined,
 * reducing the number of migrations needed.
 */
class Optimizer
{
    /**
     * Maximum optimization passes to prevent infinite loops.
     */
    private const MAX_PASSES = 10;

    /**
     * Optimize a list of operations by merging where possible.
     *
     * @param Operation[] $operations
     * @return Operation[]
     */
    public function optimize(array $operations): array
    {
        $result = $operations;
        
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $newResult = $this->optimizeInner($result);
            
            if (count($newResult) === count($result)) {
                // No more reduction possible
                break;
            }
            
            $result = $newResult;
        }
        
        return $result;
    }

    /**
     * Single pass through the operations to perform reductions.
     *
     * @param Operation[] $operations
     * @return Operation[]
     */
    private function optimizeInner(array $operations): array
    {
        $result = [];
        $count = count($operations);
        
        for ($i = 0; $i < $count; $i++) {
            $op = $operations[$i];
            $reduced = false;
            
            // Try to reduce with subsequent operations
            for ($j = $i + 1; $j < $count; $j++) {
                $nextOp = $operations[$j];
                
                // Check if we can reduce
                $reduction = $this->reduce($op, $nextOp, $operations, $i, $j);
                
                if ($reduction !== null) {
                    if ($reduction === []) {
                        // Operations cancel out - skip both
                        $i = $j;
                        $reduced = true;
                        break;
                    }
                    
                    if (is_array($reduction)) {
                        // Replace current op with reduced version
                        $op = $reduction[0] ?? $op;
                        
                        // If there are more operations to add, insert them
                        if (count($reduction) > 1) {
                            // Mark that we've consumed up to j
                            $i = $j;
                            $reduced = true;
                            break;
                        }
                        
                        $i = $j;
                        $reduced = true;
                        break;
                    }
                    
                    // true means we can skip over but keep both
                    if ($reduction === true) {
                        continue;
                    }
                    
                    // false means we hit an optimization boundary
                    if ($reduction === false) {
                        break;
                    }
                }
            }
            
            $result[] = $op;
        }
        
        return $result;
    }

    /**
     * Try to reduce two operations together.
     *
     * @param Operation $op Current operation
     * @param Operation $nextOp Next operation to try to reduce with
     * @param Operation[] $allOps All operations
     * @param int $currentIndex Index of current op
     * @param int $nextIndex Index of next op
     * @return array|bool|null True = can pass through, False = boundary, null = no match, [] = cancel out, [op] = replacement
     */
    private function reduce(Operation $op, Operation $nextOp, array $allOps, int $currentIndex, int $nextIndex): array|bool|null
    {
        // Use the operation's reduce method if available
        if (method_exists($op, 'reduce')) {
            $result = $op->reduce($nextOp, 'default');
            if ($result !== true) {
                return $result;
            }
        }
        
        // Additional manual reductions for operations that don't implement reduce()
        
        // CreateModel + DeleteModel = cancel out
        if ($op instanceof CreateModel && $nextOp instanceof DeleteModel) {
            if ($this->sameModel($op, $nextOp)) {
                return []; // Cancel both
            }
        }
        
        // CreateModel + RenameModel = CreateModel with new name
        if ($op instanceof CreateModel && $nextOp instanceof RenameModel) {
            if ($op->name === $nextOp->oldName) {
                $clone = clone $op;
                $clone->name = $nextOp->newName;
                return [$clone];
            }
        }
        
        // DeleteModel + CreateModel = DeleteModel (final state wins)
        if ($op instanceof DeleteModel && $nextOp instanceof CreateModel) {
            if ($this->sameModel($op, $nextOp)) {
                return [$nextOp]; // Keep the CreateModel (which becomes delete in reverse)
            }
        }
        
        // CreateModel + AddField = CreateModel with field
        if ($op instanceof CreateModel && $nextOp instanceof AddField) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                $clone->fields[$nextOp->name] = $nextOp->field;
                return [$clone];
            }
        }
        
        // CreateModel + AlterField = CreateModel with altered field
        if ($op instanceof CreateModel && $nextOp instanceof AlterField) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                $clone->fields[$nextOp->name] = $nextOp->field;
                return [$clone];
            }
        }
        
        // CreateModel + RemoveField = CreateModel without field
        if ($op instanceof CreateModel && $nextOp instanceof RemoveField) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                unset($clone->fields[$nextOp->name]);
                return [$clone];
            }
        }
        
        // CreateModel + RenameField = CreateModel with renamed field
        if ($op instanceof CreateModel && $nextOp instanceof RenameField) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                $oldField = $clone->fields[$nextOp->oldName] ?? null;
                if ($oldField !== null) {
                    unset($clone->fields[$nextOp->oldName]);
                    // Create a new field with the new name (but same properties)
                    $newField = clone $oldField;
                    $newField->name = $nextOp->newName;
                    $clone->fields[$nextOp->newName] = $newField;
                    return [$clone];
                }
            }
        }
        
        // CreateModel + AddIndex = CreateModel with index in options
        if ($op instanceof CreateModel && $nextOp instanceof AddIndex) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                $clone->options['indexes'] = $clone->options['indexes'] ?? [];
                $clone->options['indexes'][] = $nextOp->index;
                return [$clone];
            }
        }

        // CreateModel + AddForeignKey = CreateModel with FK handled separately (not in options)
        // This passes through - FKs are handled differently

        // AlterUniqueTogether + AlterUniqueTogether = single AlterUniqueTogether with final value
        if ($op instanceof AlterUniqueTogether && $nextOp instanceof AlterUniqueTogether) {
            if ($this->sameModel($op, $nextOp)) {
                return [$nextOp];
            }
        }

        // AlterIndexTogether + AlterIndexTogether = single AlterIndexTogether with final value
        if ($op instanceof AlterIndexTogether && $nextOp instanceof AlterIndexTogether) {
            if ($this->sameModel($op, $nextOp)) {
                return [$nextOp];
            }
        }

        // AlterModelTable + AlterModelTable = single AlterModelTable with final value
        if ($op instanceof AlterModelTable && $nextOp instanceof AlterModelTable) {
            if ($this->sameModel($op, $nextOp)) {
                return [$nextOp];
            }
        }

        // AlterModelOptions + AlterModelOptions = single AlterModelOptions with final value
        if ($op instanceof AlterModelOptions && $nextOp instanceof AlterModelOptions) {
            if ($this->sameModel($op, $nextOp)) {
                return [$nextOp];
            }
        }
        
        // CreateModel + AddConstraint = CreateModel with constraint in options
        if ($op instanceof CreateModel && $nextOp instanceof AddConstraint) {
            if ($this->sameModel($op, $nextOp)) {
                $clone = clone $op;
                $clone->options['constraints'] = $clone->options['constraints'] ?? [];
                $clone->options['constraints'][] = $nextOp->constraint;
                return [$clone];
            }
        }
        
        // AddField + RemoveField on same field = cancel out
        if ($op instanceof AddField && $nextOp instanceof RemoveField) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return []; // Cancel both
            }
        }
        
        // RemoveField + AddField on same field = AlterField
        if ($op instanceof RemoveField && $nextOp instanceof AddField) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return [new AlterField($op->modelName, $op->name, $nextOp->field)];
            }
        }
        
        // AlterField + RemoveField = just RemoveField
        if ($op instanceof AlterField && $nextOp instanceof RemoveField) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return [$nextOp];
            }
        }
        
        // AlterField + AlterField on same field = single AlterField with final state
        if ($op instanceof AlterField && $nextOp instanceof AlterField) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return [new AlterField($op->modelName, $op->name, $nextOp->field, $op->oldField)];
            }
        }
        
        // RenameField + RenameField = single RenameField with final name
        if ($op instanceof RenameField && $nextOp instanceof RenameField) {
            if ($this->sameModel($op, $nextOp) && $op->newName === $nextOp->oldName) {
                return [new RenameField($op->modelName, $op->oldName, $nextOp->newName)];
            }
        }
        
        // AddIndex + RemoveIndex on same index = cancel out
        if ($op instanceof AddIndex && $nextOp instanceof RemoveIndex) {
            if ($this->sameModel($op, $nextOp) && $op->index->name === $nextOp->name) {
                return [];
            }
        }

        // RemoveIndex + AddIndex on same index = cancel out ONLY if identical
        // Since RemoveIndex doesn't store full index details, we can't reliably compare
        // so we default to NOT canceling out (safer behavior)
        if ($op instanceof RemoveIndex && $nextOp instanceof AddIndex) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->index->name) {
                return false; // Don't cancel - can't verify identity
            }
        }
        
        // AddIndex + AddIndex on same model (different fields) = combine into model options
        // This is handled by CreateModel merging above
        
        // AddConstraint + RemoveConstraint on same constraint = cancel out
        if ($op instanceof AddConstraint && $nextOp instanceof RemoveConstraint) {
            if ($this->sameModel($op, $nextOp) && $op->constraint->name === $nextOp->name) {
                return [];
            }
        }

        // RemoveConstraint + AddConstraint on same constraint = cancel out ONLY if identical
        // Since RemoveConstraint doesn't store full constraint details, we can't reliably compare
        // so we default to NOT canceling out (safer behavior)
        if ($op instanceof RemoveConstraint && $nextOp instanceof AddConstraint) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->constraint->name) {
                return false; // Don't cancel - can't verify identity
            }
        }
        
        // AlterConstraint + RemoveConstraint = just RemoveConstraint
        if ($op instanceof AlterConstraint && $nextOp instanceof RemoveConstraint) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return [$nextOp];
            }
        }
        
        // AddForeignKey + RemoveForeignKey on same FK = cancel out
        if ($op instanceof AddForeignKey && $nextOp instanceof RemoveForeignKey) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return [];
            }
        }

        // RemoveForeignKey + AddForeignKey on same FK = cancel out ONLY if identical
        // Since RemoveForeignKey doesn't store full FK details, we can't reliably compare
        // so we default to NOT canceling out (safer behavior)
        if ($op instanceof RemoveForeignKey && $nextOp instanceof AddForeignKey) {
            if ($this->sameModel($op, $nextOp) && $op->name === $nextOp->name) {
                return false; // Don't cancel - can't verify identity
            }
        }
        
        // RenameIndex + RenameIndex = single RenameIndex with final name
        if ($op instanceof RenameIndex && $nextOp instanceof RenameIndex) {
            if ($op->modelName === $nextOp->modelName && $op->newName === $nextOp->oldName) {
                return [new RenameIndex($op->modelName, $nextOp->newName, $op->oldName)];
            }
        }
        
        // Check for operation-level optimization boundaries
        // Some operations should not be optimized through
        if ($this->isBoundary($op) || $this->isBoundary($nextOp)) {
            return false;
        }
        
        // Operations don't match - can pass through
        return true;
    }

    /**
     * Check if two operations operate on the same model.
     */
    private function sameModel(Operation $op1, Operation $op2): bool
    {
        $name1 = $this->getModelName($op1);
        $name2 = $this->getModelName($op2);
        
        return $name1 !== null && $name1 === $name2;
    }

    /**
     * Get the model name from an operation.
     */
    private function getModelName(Operation $op): ?string
    {
        if (property_exists($op, 'name') && ($op instanceof CreateModel || $op instanceof DeleteModel)) {
            return $op->name;
        }
        if (property_exists($op, 'modelName')) {
            return $op->modelName;
        }
        return null;
    }

    /**
     * Check if an operation is an optimization boundary.
     * Operations that should block optimization through them.
     */
    private function isBoundary(Operation $op): bool
    {
        // RunSQL and RunPHP are boundaries - they may have side effects
        // that we can't safely optimize through
        $class = get_class($op);
        
        return in_array($class, [
            'Nudelsalat\Migrations\Operations\RunSQL',
            'Nudelsalat\Migrations\Operations\RunPHP',
            'Nudelsalat\Migrations\Operations\SeparateDatabaseAndState',
        ], true);
    }

    /**
     * Check if two constraints are identical.
     */
    private function constraintIdentical(Operation $op1, Operation $op2): bool
    {
        if (!$op1 instanceof RemoveConstraint || !$op2 instanceof AddConstraint) {
            return false;
        }

        $newConstraint = $op2->constraint;

        if (!$newConstraint instanceof \Nudelsalat\Schema\Constraint) {
            return false;
        }

        return $op1->name === $newConstraint->name;
    }

    /**
     * Check if two indexes are identical.
     */
    private function indexIdentical(Operation $op1, Operation $op2): bool
    {
        if (!$op1 instanceof RemoveIndex || !$op2 instanceof AddIndex) {
            return false;
        }

        $newIndex = $op2->index;

        if (!$newIndex instanceof \Nudelsalat\Schema\Index) {
            return false;
        }

        return $op1->name === $newIndex->name;
    }
}