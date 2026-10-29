<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Deleting an account keeps the record of what that person did (QA PLN-02).
 *
 * Seven foreign keys passed CodeIgniter's actions the wrong way round (`onUpdate`, then `onDelete`):
 * they were meant to set the actor to NULL on delete and instead deleted the row. Three more actors
 * (who asked for an override, who signed off, who posted a department announcement) cascaded on
 * purpose; they now keep the row too, with the actor left empty. A file that a learning resource
 * still uses can no longer be deleted from under it.
 */
class KeepHistoryWhenAUserIsDeleted extends Migration
{
    /** Actor columns that become nullable and SET NULL on delete. */
    private const ACTORS = [
        'dean_assignments'         => ['assigned_by'],
        'grade_override_requests'  => ['requested_by', 'endorsed_by', 'returned_by', 'decided_by'],
        'grade_signoffs'           => ['signed_by', 'reopened_by'],
        'department_announcements' => ['created_by', 'ended_by'],
    ];

    /** The columns that were NOT NULL before. */
    private const WERE_REQUIRED = ['requested_by', 'signed_by', 'created_by'];

    public function up(): void
    {
        foreach (self::ACTORS as $table => $columns) {
            foreach ($columns as $column) {
                $this->replaceKey($table, $column, 'users', 'INT UNSIGNED NULL', 'SET NULL', 'CASCADE');
            }
        }

        $this->replaceKey('learning_resources', 'file_id', 'files', 'INT(11) UNSIGNED NULL', 'RESTRICT', 'CASCADE');
    }

    /**
     * Puts the keys' actions back. The columns that were required stay nullable: rows whose actor
     * was deleted meanwhile hold NULL, and making the column required again would refuse them.
     */
    public function down(): void
    {
        foreach (self::ACTORS as $table => $columns) {
            foreach ($columns as $column) {
                $onUpdate = in_array($column, self::WERE_REQUIRED, true) ? 'CASCADE' : 'SET NULL';
                $this->replaceKey($table, $column, 'users', 'INT UNSIGNED NULL', 'CASCADE', $onUpdate);
            }
        }

        $this->replaceKey('learning_resources', 'file_id', 'files', 'INT(11) UNSIGNED NULL', 'CASCADE', 'SET NULL');
    }

    private function replaceKey(string $table, string $column, string $references, string $definition, string $onDelete, string $onUpdate): void
    {
        $prefixed = $this->connection()->prefixTable($table);
        $key      = $prefixed . '_' . $column . '_foreign';

        $this->db->query("ALTER TABLE {$prefixed} DROP FOREIGN KEY {$key}");
        $this->db->query("ALTER TABLE {$prefixed} MODIFY {$column} {$definition}");
        $this->db->query(
            "ALTER TABLE {$prefixed} ADD CONSTRAINT {$key} FOREIGN KEY ({$column}) REFERENCES "
            . $this->connection()->prefixTable($references) . " (id) ON DELETE {$onDelete} ON UPDATE {$onUpdate}"
        );
    }

    /** The migration's own connection, typed so its prefix and row counts can be read. */
    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}
