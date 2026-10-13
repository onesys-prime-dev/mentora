<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What an institution shows about itself: contact details, a logo (a row in
 * `files`, counted against its storage) and two brand colours. All optional:
 * an institution that has set none looks like Mentora.
 */
class AddProfileAndBrandingToInstitutions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('institutions', [
            'contact_email' => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true],
            'contact_phone' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'address'       => ['type' => 'TEXT', 'null' => true],
            'logo_file_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'brand_primary' => ['type' => 'CHAR', 'constraint' => 7, 'null' => true],
            'brand_accent'  => ['type' => 'CHAR', 'constraint' => 7, 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('institutions', ['contact_email', 'contact_phone', 'address', 'logo_file_id', 'brand_primary', 'brand_accent']);
    }
}
