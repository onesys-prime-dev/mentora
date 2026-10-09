<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An institution's subscription (SA13) and its renewal history.
 *
 * `subscriptions` is the current state: one row per institution, on a plan,
 * running from start_date to end_date.
 *
 * `subscription_renewals` is the record of how it got there: one row per
 * period, written once and never changed (see SubscriptionRenewalModel).
 * The first period is kind `start`; each renewal is kind `renewal`; a mistake
 * in an earlier row (a wrong amount or reference) is put right with a new
 * row of kind `correction` that points at the row it corrects, never by
 * editing it. Amounts are for reference only: payment happens elsewhere.
 *
 * Removing an institution (a purge) removes its subscription and history
 * with it; the audit log keeps the record of what was removed.
 */
class CreateSubscriptionsTables extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'institution_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'plan_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'start_date' => [
                'type' => 'DATE',
            ],

            'end_date' => [
                'type' => 'DATE',
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('institution_id');
        $this->forge->addKey('plan_id');
        $this->forge->addKey('end_date');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'plans', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('subscriptions');

        $this->forge->addField([

            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'subscription_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'kind' => [
                'type'       => 'ENUM',
                'constraint' => ['start', 'renewal', 'correction'],
            ],

            // For a correction: the row it puts right.
            'corrects_renewal_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'plan_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'period_start' => [
                'type' => 'DATE',
            ],

            'period_end' => [
                'type' => 'DATE',
            ],

            // Reference only; nothing is charged here. NULL = not recorded.
            'amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '12,2',
                'null'       => true,
            ],

            'currency' => [
                'type'       => 'CHAR',
                'constraint' => 3,
                'default'    => 'PHP',
            ],

            // Invoice number, transfer reference, and so on.
            'reference' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],

            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            // Who recorded it. No foreign key: the record outlives the account.
            'recorded_by_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'recorded_by_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],

            'recorded_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('subscription_id');
        $this->forge->addKey('plan_id');
        $this->forge->addKey('corrects_renewal_id');
        $this->forge->addForeignKey('subscription_id', 'subscriptions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'plans', 'id', 'CASCADE', 'RESTRICT');

        $this->forge->createTable('subscription_renewals');
    }

    public function down()
    {
        $this->forge->dropTable('subscription_renewals');
        $this->forge->dropTable('subscriptions');
    }
}
