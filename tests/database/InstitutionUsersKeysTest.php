<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * A person belongs to one institution (D1): the unique key on user_id says so, and the older
 * (institution_id, user_id) key it made redundant is gone.
 *
 * @internal
 */
final class InstitutionUsersKeysTest extends DatabaseTestCase
{
    /** @return array<string, array{unique: bool, columns: list<string>}> by key name */
    private function keys(): array
    {
        $keys = [];

        foreach (db_connect()->query('SHOW INDEX FROM ' . db_connect()->prefixTable('institution_users'))->getResultArray() as $row) {
            $keys[$row['Key_name']]['unique']    = (int) $row['Non_unique'] === 0;
            $keys[$row['Key_name']]['columns'][] = $row['Column_name'];
        }

        return $keys;
    }

    public function testOnlyTheOneMembershipKeyIsLeft(): void
    {
        $keys = $this->keys();

        $this->assertArrayNotHasKey('institution_user_unique', $keys);
        $this->assertContains(['unique' => true, 'columns' => ['user_id']], array_values($keys));
        $this->assertContains(['unique' => false, 'columns' => ['institution_id']], array_values($keys), 'the foreign key keeps its index');
    }
}
