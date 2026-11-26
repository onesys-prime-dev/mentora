<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Platform;

/**
 * D3: an institution that switched student messaging off (studentDmEnabled = false) gets
 * studentMessagingScope = 'off'. The old override is copied, never changed or removed; institutions without
 * one keep the default ('institution'). down() removes only the new key.
 */
class MapStudentDmSetting extends Migration
{
    public function up()
    {
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        $old = $db->table('settings')->where(['class' => Platform::class, 'key' => 'studentDmEnabled'])->like('context', 'institution:', 'after')->get()->getResultArray();

        foreach ($old as $row) {
            if (! in_array(strtolower(trim((string) $row['value'])), ['0', 'false', ''], true)) {
                continue;
            }

            $exists = $db->table('settings')->where(['class' => Platform::class, 'key' => 'studentMessagingScope', 'context' => $row['context']])->countAllResults() > 0;

            if (! $exists) {
                $db->table('settings')->insert([
                    'class' => Platform::class, 'key' => 'studentMessagingScope', 'value' => 'off', 'type' => 'string',
                    'context' => $row['context'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        $this->db->table('settings')->where(['class' => Platform::class, 'key' => 'studentMessagingScope'])->like('context', 'institution:', 'after')->delete();
    }
}
