<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * The two small tables behind the parent notifications: a parent's email
 * choice per child, and the items a child's parents have already been told
 * about (so an item is reported once, ever).
 *
 * @internal
 */
final class ParentNotificationTablesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function stores(string $table, array $row): bool
    {
        try {
            // A refused row is a thrown error or, with debugging off, a false.
            return db_connect()->table($table)->insert($row + ['created_at' => date('Y-m-d H:i:s')]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function testTheTablesExist(): void
    {
        $this->assertTrue(db_connect()->tableExists('parent_child_preferences'));
        $this->assertTrue(db_connect()->tableExists('parent_alerted_items'));
    }

    public function testThereIsOneChoiceRowPerParentAndChild(): void
    {
        $this->assertTrue($this->stores('parent_child_preferences', ['parent_user_id' => 1, 'student_user_id' => 2, 'email_enabled' => 0]));
        $this->assertFalse($this->stores('parent_child_preferences', ['parent_user_id' => 1, 'student_user_id' => 2, 'email_enabled' => 1]));
        $this->assertTrue($this->stores('parent_child_preferences', ['parent_user_id' => 1, 'student_user_id' => 3, 'email_enabled' => 1]));
        $this->assertTrue($this->stores('parent_child_preferences', ['parent_user_id' => 4, 'student_user_id' => 2, 'email_enabled' => 1]));
    }

    public function testAnEmailChoiceIsOnUnlessSaidOtherwise(): void
    {
        $this->stores('parent_child_preferences', ['parent_user_id' => 5, 'student_user_id' => 6]);

        $this->assertSame(1, (int) db_connect()->table('parent_child_preferences')->where(['parent_user_id' => 5, 'student_user_id' => 6])->get()->getRowArray()['email_enabled']);
    }

    public function testAnItemIsRecordedOncePerChildAndKind(): void
    {
        $this->assertTrue($this->stores('parent_alerted_items', ['student_user_id' => 1, 'kind' => 'missing', 'ref_id' => 10]));
        $this->assertFalse($this->stores('parent_alerted_items', ['student_user_id' => 1, 'kind' => 'missing', 'ref_id' => 10]));
        $this->assertTrue($this->stores('parent_alerted_items', ['student_user_id' => 1, 'kind' => 'missing', 'ref_id' => 11]));
        $this->assertTrue($this->stores('parent_alerted_items', ['student_user_id' => 2, 'kind' => 'missing', 'ref_id' => 10]));
        $this->assertTrue($this->stores('parent_alerted_items', ['student_user_id' => 1, 'kind' => 'other', 'ref_id' => 10]));
    }
}
