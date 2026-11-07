<?php

namespace App\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * Upgrades a database that still has the pre-SIS hierarchy (departments -> sub_departments) to the one the SIS uses:
 *
 *     institution -> college -> department -> program
 *
 * What Mentora used to call a "department" is now a college, and what it called a "department" is now a
 * department. Tables, columns, keys, indexes and the names stored in audit entries, notifications and permission
 * grants all follow. The two words swap meaning, so every name goes through one two-step mapping (sub_department ->
 * department happens only after department -> college has been set aside).
 *
 * Only the first migration of the hierarchy calls this, and only on a database that was built before the rename;
 * a new database is created with the final names and never sees the old ones. This is the one place the old names
 * are spelled out, because recognising them is the whole job.
 */
final class LegacyHierarchyRename
{
    /** Tables whose own name, columns, indexes or foreign keys carry the word. */
    private const AFFECTED = ['departments', 'sub_departments', 'department_announcements', 'programs', 'chairperson_assignments', 'dean_assignments'];

    /** Stored values that are names too: table => columns. */
    private const NAME_VALUES = [
        'audit_logs'        => ['action', 'target_type'],
        'notifications'     => ['type'],
        'role_permissions'  => ['permission'],
        'college_announcements' => ['scope_type'],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Whether the database still has the old two-level hierarchy. */
    public function isNeeded(): bool
    {
        return $this->db->tableExists('sub_departments');
    }

    public function upgrade(): void
    {
        $this->rename(true);

        // The connection remembers the table and column lists it read before the rename.
        $this->db->resetDataCache();
    }

    private function rename(bool $forward): void
    {
        $db       = $this->db;
        // The hierarchy may be partly built (a database that stopped before the later tables existed).
        $affected = array_values(array_filter(
            $forward ? self::AFFECTED : array_map(fn (string $table): string => $this->map($table, true), self::AFFECTED),
            static fn (string $table): bool => $db->tableExists($table)
        ));

        $foreignKeys = $this->foreignKeys($db, $affected);
        $indexes     = $this->indexes($db, $affected);
        $columns     = $this->columnsToRename($db, $affected, $forward);

        foreach ($foreignKeys as $fk) {
            $db->query("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['name']}`");
        }

        foreach ($indexes as $index) {
            $db->query("ALTER TABLE `{$index['table']}` DROP INDEX `{$index['name']}`");
        }

        // Order matters going forward (departments must be free before sub_departments takes its name) and
        // backward (the reverse of it).
        $tables = [];

        foreach ($affected as $table) {
            $new = $this->map($table, $forward);

            if ($new !== $table) {
                $tables[$table] = $new;
            }
        }

        // Park every table that is about to change under a throwaway name, then give each its final one.
        foreach ($tables as $from => $to) {
            $db->query("RENAME TABLE `{$from}` TO `__rn_{$from}`");
        }

        foreach ($tables as $from => $to) {
            $db->query("RENAME TABLE `__rn_{$from}` TO `{$to}`");
        }

        $current = static fn (string $table): string => $tables[$table] ?? $table;

        foreach ($columns as $column) {
            $db->query(sprintf(
                'ALTER TABLE `%s` CHANGE `%s` `%s` %s',
                $current($column['table']),
                $column['from'],
                $column['to'],
                $column['definition']
            ));
        }

        foreach ($indexes as $index) {
            $cols = implode(', ', array_map(fn (string $c): string => '`' . $this->map($c, $forward) . '`', $index['columns']));
            $name = $this->map($index['name'], $forward);
            $kind = $index['unique'] ? 'UNIQUE KEY' : 'KEY';
            $db->query("ALTER TABLE `{$current($index['table'])}` ADD {$kind} `{$name}` ({$cols})");
        }

        foreach ($foreignKeys as $fk) {
            $db->query(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
                $current($fk['table']),
                $this->map($fk['name'], $forward),
                implode(', ', array_map(fn (string $c): string => '`' . $this->map($c, $forward) . '`', $fk['columns'])),
                $current($fk['referenced_table']),
                implode(', ', array_map(fn (string $c): string => '`' . $this->map($c, $forward) . '`', $fk['referenced_columns'])),
                $fk['delete_rule'],
                $fk['update_rule']
            ));
        }

        $db->resetDataCache();
        $this->renameStoredNames($db, $forward);
    }

    /** sub_department -> department, department -> college (and back). */
    private function map(string $name, bool $forward): string
    {
        if ($forward) {
            return str_replace('#sd#', 'department', str_replace('department', 'college', str_replace('sub_department', '#sd#', $name)));
        }

        return str_replace('#sd#', 'sub_department', str_replace('college', 'department', str_replace('department', '#sd#', $name)));
    }

    /** @param list<string> $affected */
    private function foreignKeys(BaseConnection $db, array $affected): array
    {
        $in   = $this->in($db, $affected);
        $rows = $db->query(
            "SELECT k.CONSTRAINT_NAME AS name, k.TABLE_NAME AS tbl, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_tbl,
                    k.REFERENCED_COLUMN_NAME AS ref_col, r.DELETE_RULE AS del, r.UPDATE_RULE AS upd
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
               AND (k.TABLE_NAME IN ({$in}) OR k.REFERENCED_TABLE_NAME IN ({$in}))
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        )->getResultArray();

        $keys = [];

        foreach ($rows as $row) {
            $id = $row['tbl'] . '.' . $row['name'];
            $keys[$id] ??= [
                'name' => $row['name'], 'table' => $row['tbl'], 'referenced_table' => $row['ref_tbl'],
                'columns' => [], 'referenced_columns' => [], 'delete_rule' => $row['del'], 'update_rule' => $row['upd'],
            ];
            $keys[$id]['columns'][]            = $row['col'];
            $keys[$id]['referenced_columns'][] = $row['ref_col'];
        }

        return array_values($keys);
    }

    /** Non-primary indexes of the affected tables whose own name or columns carry the word. */
    private function indexes(BaseConnection $db, array $affected): array
    {
        $found = [];

        foreach ($affected as $table) {
            if (! $db->tableExists($table)) {
                continue;
            }

            foreach ($db->query("SHOW INDEX FROM `{$table}`")->getResultArray() as $row) {
                if ($row['Key_name'] === 'PRIMARY') {
                    continue;
                }

                $id = $table . '.' . $row['Key_name'];
                $found[$id] ??= ['table' => $table, 'name' => $row['Key_name'], 'unique' => (int) $row['Non_unique'] === 0, 'columns' => []];
                $found[$id]['columns'][] = $row['Column_name'];
            }
        }

        // Every index of an affected table is dropped and re-added, so a renamed column or a foreign key that needed
        // the index (dropped just before) never leaves the table in between. Names that do not change come back as is.
        return array_values($found);
    }

    /** Columns whose name carries the word, with the definition they keep. */
    private function columnsToRename(BaseConnection $db, array $affected, bool $forward): array
    {
        $in   = $this->in($db, $affected);
        $rows = $db->query(
            "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            $to = $this->map($row['col'], $forward);

            if ($to === $row['col']) {
                continue;
            }

            $definition = $row['type'] . ($row['nullable'] === 'YES' ? ' NULL' : ' NOT NULL');

            if ($row['dflt'] !== null && $row['dflt'] !== 'NULL') {
                $definition .= ' DEFAULT ' . (preg_match('/^-?\d+(\.\d+)?$|^current_timestamp/i', (string) $row['dflt']) === 1 ? $row['dflt'] : $db->escape($row['dflt']));
            } elseif ($row['nullable'] === 'YES') {
                $definition .= ' DEFAULT NULL';
            }

            if (stripos((string) $row['extra'], 'auto_increment') !== false) {
                $definition .= ' AUTO_INCREMENT';
            }

            $columns[] = ['table' => $row['tbl'], 'from' => $row['col'], 'to' => $to, 'definition' => $definition];
        }

        return $columns;
    }

    /** Audit entries, notifications and permission grants keep their old names; give them the new ones. */
    private function renameStoredNames(BaseConnection $db, bool $forward): void
    {
        foreach (self::NAME_VALUES as $table => $fields) {
            if (! $db->tableExists($table)) {
                continue;
            }

            foreach ($fields as $field) {
                if ($forward) {
                    $expr = "REPLACE(REPLACE(REPLACE(`{$field}`, 'sub_department', '#sd#'), 'department', 'college'), '#sd#', 'department')";
                } else {
                    $expr = "REPLACE(REPLACE(REPLACE(`{$field}`, 'department', '#sd#'), 'college', 'department'), '#sd#', 'sub_department')";
                }

                $db->query("UPDATE `{$table}` SET `{$field}` = {$expr} WHERE `{$field}` LIKE '%department%' OR `{$field}` LIKE '%college%'");
            }
        }
    }

    /** @param list<string> $names */
    private function in(BaseConnection $db, array $names): string
    {
        return implode(', ', array_map(static fn (string $n): string => $db->escape($n), $names));
    }
}
