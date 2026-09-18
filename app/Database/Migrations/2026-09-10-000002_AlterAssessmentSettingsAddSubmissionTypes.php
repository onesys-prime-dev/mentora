<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentSettingsAddSubmissionTypes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_settings', [
            'allow_file_upload' => [
                'type'       => 'BOOLEAN',
                'default'    => true,
                'after'      => 'assessment_id',
            ],
            'allow_text_entry' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'after'      => 'allow_file_upload',
            ],
            'allow_link_entry' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'after'      => 'allow_text_entry',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessment_settings', [
            'allow_file_upload',
            'allow_text_entry',
            'allow_link_entry',
        ]);
    }
}
