<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: the minor flag, set by an institution's administrator on a student (FR8). Off for everyone until set.
 */
class AddMinorFlag extends Migration
{
    public function up()
    {
        $this->forge->addColumn('institution_users', [
            'is_minor' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('institution_users', 'is_minor');
    }
}
