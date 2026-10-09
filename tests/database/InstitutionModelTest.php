<?php

declare(strict_types=1);

use App\Models\Academic\InstitutionModel;
use Tests\Support\DatabaseTestCase;

/**
 * Institutions as the model presents them: status scopes, soft delete, and
 * the rule that a code, once given, never changes.
 *
 * @internal
 */
final class InstitutionModelTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private InstitutionModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new InstitutionModel();
    }

    private function create(string $code, string $status = 'active'): int
    {
        $id = $this->model->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => $status]);

        $this->assertNotFalse($id, json_encode($this->model->errors()));

        return (int) $id;
    }

    /** @param list<array<string, mixed>> $rows */
    private function codes(array $rows): array
    {
        $codes = array_column($rows, 'code');
        sort($codes);

        return $codes;
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function testTheDatabaseDefaultsAnInstitutionToActive(): void
    {
        db_connect()->table('institutions')->insert(['name' => 'Default Univ', 'code' => 'DEF']);

        $this->assertSame('active', $this->model->find((int) db_connect()->insertID())['status']);
    }

    public function testAStatusMustBeGivenWhenCreating(): void
    {
        $this->assertFalse($this->model->insert(['name' => 'No Status Univ', 'code' => 'NOS']));
        $this->assertArrayHasKey('status', $this->model->errors());
    }

    public function testEveryLifecycleStatusIsAccepted(): void
    {
        foreach (InstitutionModel::STATUSES as $i => $status) {
            $this->assertNotFalse($this->model->insert(['name' => "Univ {$i}", 'code' => "ST{$i}", 'status' => $status]), $status);
        }
    }

    public function testTheOldStatusesAreRefused(): void
    {
        foreach (['inactive', 'archived', 'deleted', 'bogus'] as $i => $status) {
            $this->assertFalse($this->model->insert(['name' => "Univ {$i}", 'code' => "BAD{$i}", 'status' => $status]), "{$status} must be refused");
            $this->assertArrayHasKey('status', $this->model->errors());
        }
    }

    public function testEachScopeReturnsOnlyItsOwnStatus(): void
    {
        $this->create('P1', 'pending');
        $this->create('A1', 'active');
        $this->create('A2', 'active');
        $this->create('R1', 'read_only');
        $this->create('X1', 'past');

        $this->assertSame(['P1'], $this->codes((new InstitutionModel())->pending()->findAll()));
        $this->assertSame(['A1', 'A2'], $this->codes((new InstitutionModel())->active()->findAll()));
        $this->assertSame(['R1'], $this->codes((new InstitutionModel())->readOnly()->findAll()));
        $this->assertSame(['X1'], $this->codes((new InstitutionModel())->past()->findAll()));
    }

    /*
    |--------------------------------------------------------------------------
    | Soft delete
    |--------------------------------------------------------------------------
    */

    public function testDeletingHidesAnInstitutionButKeepsItsRow(): void
    {
        $id = $this->create('DEL1');

        $this->model->delete($id);

        $this->assertNull($this->model->find($id));
        $this->assertSame([], (new InstitutionModel())->findAll());

        $row = db_connect()->table('institutions')->where('id', $id)->get()->getRowArray();

        $this->assertNotNull($row, 'the row is still there');
        $this->assertNotNull($row['deleted_at']);
    }

    public function testDeletedInstitutionsCanBeFoundOnRequest(): void
    {
        $this->create('KEEP');
        $deleted = $this->create('GONE');
        $this->model->delete($deleted);

        $this->assertSame(['GONE'], $this->codes((new InstitutionModel())->onlyDeleted()->findAll()));
        $this->assertSame(['GONE', 'KEEP'], $this->codes((new InstitutionModel())->withDeleted()->findAll()));
        $this->assertSame(['KEEP'], $this->codes((new InstitutionModel())->findAll()));
    }

    public function testStatusScopesLeaveOutDeletedInstitutions(): void
    {
        $id = $this->create('ACT1');
        $this->create('ACT2');
        $this->model->delete($id);

        $this->assertSame(['ACT2'], $this->codes((new InstitutionModel())->active()->findAll()));
    }

    public function testADeletedInstitutionKeepsItsStatusForWhenItIsRestored(): void
    {
        $id = $this->create('PAST1', 'past');
        $this->model->delete($id);

        $this->assertSame('past', (new InstitutionModel())->withDeleted()->find($id)['status']);
    }

    public function testADeletedInstitutionsCodeCannotBeTakenByAnotherInstitution(): void
    {
        $id = $this->create('RESERVED');
        $this->model->delete($id);

        $this->assertFalse($this->model->insert(['name' => 'Impostor', 'code' => 'RESERVED']), 'the code is held until it is purged');
    }

    public function testRestoringBringsADeletedInstitutionBackWithItsStatus(): void
    {
        $id = $this->create('BACK', 'past');
        $this->model->delete($id);

        $this->assertTrue($this->model->restore($id));

        $row = (new InstitutionModel())->find($id);

        $this->assertNotNull($row);
        $this->assertNull($row['deleted_at']);
        $this->assertSame('past', $row['status']);
    }

    public function testRestoringSomethingNotDeletedOrMissingChangesNothing(): void
    {
        $id = $this->create('LIVE');

        $this->assertFalse($this->model->restore($id), 'it was never deleted');
        $this->assertFalse($this->model->restore(999999), 'there is no such institution');
    }

    public function testPurgingRemovesTheRowForGood(): void
    {
        $id = $this->create('PURGE1');
        $this->model->delete($id, true);

        $this->assertNull((new InstitutionModel())->withDeleted()->find($id));
        $this->assertNotFalse($this->model->insert(['name' => 'New owner', 'code' => 'PURGE1', 'status' => 'active']), 'a purged code is free again');
    }

    /*
    |--------------------------------------------------------------------------
    | The code never changes
    |--------------------------------------------------------------------------
    */

    public function testChangingTheCodeIsRefused(): void
    {
        $id = $this->create('FIXED');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('code cannot be changed');

        $this->model->update($id, ['code' => 'MOVED']);
    }

    public function testTheCodeIsLeftAsItWasAfterARefusedChange(): void
    {
        $id = $this->create('STAYS');

        try {
            $this->model->update($id, ['code' => 'GOES']);
        } catch (LogicException) {
            // expected
        }

        $this->assertSame('STAYS', $this->model->find($id)['code']);
    }

    public function testSavingTheSameCodeAgainIsFine(): void
    {
        $id = $this->create('SAME');

        $this->assertTrue($this->model->update($id, ['name' => 'Renamed', 'code' => 'SAME']));
        $this->assertSame('Renamed', $this->model->find($id)['name']);
    }

    public function testOtherFieldsCanBeChangedFreely(): void
    {
        $id = $this->create('FREE');

        $this->assertTrue($this->model->update($id, ['name' => 'New Name', 'status' => 'past']));

        $row = $this->model->find($id);

        $this->assertSame('New Name', $row['name']);
        $this->assertSame('past', $row['status']);
        $this->assertSame('FREE', $row['code']);
    }

    public function testSaveCannotChangeTheCodeEither(): void
    {
        $id = $this->create('SAVE1');

        $this->expectException(LogicException::class);

        $this->model->save(['id' => $id, 'name' => 'Valid Name', 'code' => 'SAVE2']);
    }

    public function testChangingTheCodeOfSeveralAtOnceIsRefused(): void
    {
        $this->create('M1');
        $this->create('M2');

        $this->expectException(LogicException::class);

        $this->model->whereIn('code', ['M1', 'M2'])->set(['code' => 'ALLSAME'])->update();
    }

    public function testTheCodeOfADeletedInstitutionCannotBeChangedEither(): void
    {
        $id = $this->create('OLD1');
        $this->model->delete($id);

        $this->expectException(LogicException::class);

        (new InstitutionModel())->withDeleted()->update($id, ['code' => 'OLD2']);
    }

    public function testUpdatingAnInstitutionThatDoesNotExistIsNotACodeChange(): void
    {
        $this->assertTrue($this->model->update(999999, ['code' => 'ANY']), 'nothing to compare against, nothing changes');
    }

    public function testACodeMustStillBeUniqueWhenCreating(): void
    {
        $this->create('UNIQ');

        $this->assertFalse($this->model->insert(['name' => 'Twin', 'code' => 'UNIQ']));
        $this->assertArrayHasKey('code', $this->model->errors());
    }
}
