<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\Inspector;
use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class InspectorStateTest extends TestCase
{
    public function testInspectorBuildsIndexesConstraintsAndAppMetadataFromModels(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeModel('Product', <<<'PHP'
<?php
namespace App\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\StringField;
use Nudelsalat\Migrations\Fields\ForeignKey;

class Product extends Model
{
    public static array $options = [
        'db_table' => 'products',
        'indexes' => [['name' => 'products_status_idx', 'columns' => ['status']]],
        'constraints' => [['name' => 'products_slug_unique', 'type' => 'unique', 'columns' => ['slug']]],
    ];

    public static function fields(): array
    {
        return [
            'slug' => new StringField(options: ['unique' => true]),
            'status' => new StringField(options: ['db_index' => true]),
            'category' => new ForeignKey('categories'),
        ];
    }
}
PHP);

        $state = (new Inspector())->inspect(['catalog' => ['models' => $project->modelsDir, 'database' => 'analytics']]);
        $table = $state->getTable('products');

        $this->assertSame('catalog', $table->options['app_label']);
        $this->assertSame('analytics', $table->options['database']);
        $this->assertTrue(isset($table->getIndexes()['products_status_idx']));
        $this->assertTrue(isset($table->getIndexes()['idx_products_category_id']));
        $this->assertTrue(isset($table->getConstraints()['products_slug_unique']));
        $this->assertTrue(isset($table->getConstraints()['uniq_products_slug']));
        $this->assertCount(1, $table->getForeignKeys());
    }
}
