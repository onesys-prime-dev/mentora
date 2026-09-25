<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How a learning item is locked for students:
 *  - none:     always open
 *  - previous: until the item directly before it in the same unit is completed
 *  - custom:   until every item listed in learning_item_prerequisites is completed
 */
class AlterLearningItemsAddPrerequisiteMode extends Migration
{
    public function up()
    {
        $this->forge->addColumn('learning_items', [
            'prerequisite_mode' => [
                'type'       => 'ENUM',
                'constraint' => ['none', 'previous', 'custom'],
                'default'    => 'none',
                'null'       => false,
                'after'      => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('learning_items', 'prerequisite_mode');
    }
}
