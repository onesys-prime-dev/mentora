<?php

declare(strict_types=1);

use App\Services\Courses\CollegeAnnouncementService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The dean's college announcements: whom each one reaches (the college, a department or a
 * program, narrowed by role), that the two ways of working it out agree, an instructor in two colleges,
 * posting, editing and ending, what they audit and whom they notify.
 *
 * The fixture, in college H (the one with the dean):
 *
 *   Department A: program A1 (IT101: student s1; instructor Tess), program A2 (CS101: instructor Mia; s3 has A2 as their program, no enrolment)
 *   Department B: program B1 (LW100: student s2; instructor Ben)
 *   chairperson Ann for Department A, Bob for Department B
 *   not reached anywhere: s4 (dropped from IT101), s5 (enrolled in college N), s6 (inactive, enrolled in IT101),
 *   a completed IT102 section's instructor Carl
 * and college N, where Mia also teaches (NX100).
 *
 * @internal
 */
final class CollegeAnnouncementServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CollegeAnnouncementService $service;
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $departmentB;
    private int $programA1;
    private int $programA2;
    private int $programB1;
    private int $otherCollege;
    private User $dean;

    /** @var array<string, User> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::college('H');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $this->departmentB        = DeanFactory::department($this->college, 'B');
        $this->programA1   = DeanFactory::program($this->departmentA, 'A1');
        $this->programA2   = DeanFactory::program($this->departmentA, 'A2');
        $this->programB1   = DeanFactory::program($this->departmentB, 'B1');
        $term              = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->college);
        $this->service     = new CollegeAnnouncementService();

        foreach (['tess' => 'instructor', 'mia' => 'instructor', 'ben' => 'instructor', 'carl' => 'instructor', 's1' => 'student', 's2' => 'student', 's3' => 'student', 's4' => 'student', 's5' => 'student'] as $name => $role) {
            $this->p[$name] = PeopleFactory::person($role, ucfirst($name), 'X', null, $this->institution);
        }

        $this->p['s6']  = PeopleFactory::person('student', 'S6', 'X', null, $this->institution, false);
        $this->p['ann'] = DeanFactory::chair($this->departmentA, 'Ann');
        $this->p['bob'] = DeanFactory::chair($this->departmentB, 'Bob');

        $it101 = DeanFactory::offering($this->programA1, $term, 'IT101');
        $cs101 = DeanFactory::offering($this->programA2, $term, 'CS101');
        $lw100 = DeanFactory::offering($this->programB1, $term, 'LW100');
        $it102 = DeanFactory::offering($this->programA1, $term, 'IT102', 'A', 'completed');

        CourseFactory::assignInstructor($it101, $this->p['tess']);
        CourseFactory::assignInstructor($cs101, $this->p['mia']);
        CourseFactory::assignInstructor($lw100, $this->p['ben']);
        CourseFactory::assignInstructor($it102, $this->p['carl']);

        CourseFactory::enroll($it101, $this->p['s1']);
        CourseFactory::enroll($it101, $this->p['s4'], 'dropped');
        CourseFactory::enroll($it101, $this->p['s6']);
        CourseFactory::enroll($lw100, $this->p['s2']);
        db_connect()->table('user_profiles')->where('user_id', $this->p['s3']->id)->update(['program_id' => $this->programA2]);

        $other               = DeanFactory::college('N', $this->institution);
        $this->otherCollege = $other['college'];
        $nx100               = DeanFactory::offering(DeanFactory::program(DeanFactory::department($this->otherCollege, 'N'), 'N'), $term, 'NX100');
        CourseFactory::assignInstructor($nx100, $this->p['mia']);
        CourseFactory::enroll($nx100, $this->p['s5']);
    }

    /** @param array<string, mixed> $over */
    private function announce(array $over = []): int
    {
        $result = $this->service->post($this->dean, $over + ['scope' => 'college', 'audience' => 'everyone', 'title' => 'Enrolment week', 'message' => 'Please see the registrar.']);

        $this->assertTrue($result['success'], $result['message']);

        return $result['id'];
    }

    /** @return list<string> sorted names of the people an announcement reaches */
    private function reached(int $id): array
    {
        $by    = $this->service->recipients($this->service->find($id));
        $names = [];

        foreach (array_merge($by['students'], $by['instructors'], $by['chairpersons']) as $userId) {
            $names[] = array_search($userId, array_map(static fn (User $u): int => (int) $u->id, $this->p), true);
        }

        sort($names);

        return $names;
    }

    public function testTheWholeCollegeReachesItsStudentsInstructorsAndChairpersons(): void
    {
        $this->assertSame(['ann', 'ben', 'bob', 'mia', 's1', 's2', 's3', 'tess'], $this->reached($this->announce()));
    }

    public function testNobodyWhoIsNotReallyInTheCollegeRightNowIsReached(): void
    {
        $reached = $this->reached($this->announce());

        $this->assertNotContains('s4', $reached, 'dropped from the section');
        $this->assertNotContains('s5', $reached, 'enrolled in another college');
        $this->assertNotContains('s6', $reached, 'an inactive account');
        $this->assertNotContains('carl', $reached, 'teaches only a completed section');
    }

    public function testADepartmentReachesOnlyItsProgramsAndItsChairperson(): void
    {
        $this->assertSame(['ann', 'mia', 's1', 's3', 'tess'], $this->reached($this->announce(['scope' => 'department:' . $this->departmentA])));
        $this->assertSame(['ben', 'bob', 's2'], $this->reached($this->announce(['scope' => 'department:' . $this->departmentB, 'title' => 'B only'])));
    }

    public function testAProgramReachesThoseInItAndTheChairpersonOfItsDepartment(): void
    {
        $this->assertSame(['ann', 's1', 'tess'], $this->reached($this->announce(['scope' => 'program:' . $this->programA1])));
        $this->assertSame(['ann', 'mia', 's3'], $this->reached($this->announce(['scope' => 'program:' . $this->programA2, 'title' => 'A2'])), 'a student is in a program through their profile too');
    }

    public function testTheRoleFilterNarrowsToStaffOrToStudents(): void
    {
        $this->assertSame(['ann', 'ben', 'bob', 'mia', 'tess'], $this->reached($this->announce(['audience' => 'staff'])));
        $this->assertSame(['s1', 's2', 's3'], $this->reached($this->announce(['audience' => 'students', 'title' => 'Students'])));
    }

    public function testAnInstructorInTwoCollegesGetsEachOnesAnnouncementsForTheirOwnSectionsThere(): void
    {
        $here = $this->announce(['scope' => 'program:' . $this->programA2]);
        $dean = DeanFactory::dean($this->institution, $this->otherCollege, 'Eve');
        $nx   = $this->service->post($dean, ['scope' => 'college', 'audience' => 'staff', 'title' => 'Yonder', 'message' => 'From N.'])['id'];

        $mia = array_column($this->service->forUser($this->p['mia']), 'id');

        $this->assertEqualsCanonicalizing([$here, $nx], $mia, 'both colleges reach her');
        $this->assertSame([$here], array_column($this->service->forUser($this->p['ann']), 'id'));
        $this->assertSame([], $this->service->forUser($this->p['tess']), 'Tess teaches nothing in A2, and the other college is not hers');
        $this->assertSame(['mia'], $this->reached($nx), 'N has a single instructor, and no chairperson or students for staff');
    }

    public function testWorkingOutRecipientsAndWorkingOutAPersonsAnnouncementsAgree(): void
    {
        $ids = [
            $this->announce(),
            $this->announce(['scope' => 'department:' . $this->departmentA, 'audience' => 'students', 'title' => 'A students']),
            $this->announce(['scope' => 'department:' . $this->departmentB, 'audience' => 'staff', 'title' => 'B staff']),
            $this->announce(['scope' => 'program:' . $this->programA1, 'title' => 'A1']),
            $this->announce(['scope' => 'program:' . $this->programA2, 'audience' => 'staff', 'title' => 'A2 staff']),
        ];

        foreach ($this->p as $name => $person) {
            $shown = array_column($this->service->forUser($person), 'id');

            foreach ($ids as $id) {
                $this->assertSame(in_array($name, $this->reached($id), true), in_array($id, $shown, true), "{$name} and announcement {$id} (" . json_encode(array_intersect_key($this->service->find($id), array_flip(['scope_type', 'scope_id', 'audience']))) . '): reached by ' . implode(',', $this->reached($id)));
            }
        }
    }

    public function testAnEndedOrPastAnnouncementStopsShowingAndTheLastDayStillCounts(): void
    {
        $today    = $this->announce(['ends_on' => date('Y-m-d'), 'title' => 'Today']);
        $tomorrow = $this->announce(['ends_on' => date('Y-m-d', strtotime('+1 day')), 'title' => 'Tomorrow']);
        $ended    = $this->announce(['title' => 'Ended']);
        $this->service->end($this->dean, $ended);

        $shown = static fn (array $rows): array => array_column($rows, 'id');
        $s1    = $this->p['s1'];

        $this->assertEqualsCanonicalizing([$today, $tomorrow], $shown($this->service->forUser($s1)));
        $this->assertEqualsCanonicalizing([$tomorrow], $shown($this->service->forUser($s1, null, date('Y-m-d', strtotime('+1 day')))), 'the day after its last day, today\'s is gone');
        $this->assertSame([], $shown($this->service->forUser($s1, null, date('Y-m-d', strtotime('+2 days')))));
    }

    public function testForUserIsNewestFirstAndCanBeLimited(): void
    {
        $first  = $this->announce(['title' => 'First']);
        $second = $this->announce(['title' => 'Second']);

        $this->assertSame([$second, $first], array_column($this->service->forUser($this->p['s1']), 'id'));
        $this->assertSame([$second], array_column($this->service->forUser($this->p['s1'], 1), 'id'));

        $row = $this->service->forUser($this->p['s1'])[0];

        $this->assertSame('Whole College H', $row['scope_label']);
        $this->assertStringContainsString('Dana', $row['author']);
    }

    public function testPostingStoresItAuditsItAndTellsEveryoneItIsForExceptTheDean(): void
    {
        $id = $this->announce(['scope' => 'department:' . $this->departmentA, 'ends_on' => date('Y-m-d', strtotime('+7 days'))]);

        $row = $this->service->find($id);

        $this->assertSame('department', $row['scope_type']);
        $this->assertSame($this->departmentA, (int) $row['scope_id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame($this->college, (int) $row['college_id']);

        $notified = array_column(db_connect()->table('notifications')->select('user_id')->where('dedupe_key', "college-announcement:{$id}")->get()->getResultArray(), 'user_id');

        $this->assertCount(5, $notified);
        $this->assertNotContains((string) $this->dean->id, array_map('strval', $notified));
        $this->assertSame('college_announcement', db_connect()->table('notifications')->where('user_id', $this->p['s1']->id)->get()->getRowArray()['type']);
        $this->assertStringEndsWith('student/announcements', (string) db_connect()->table('notifications')->where('user_id', $this->p['s1']->id)->get()->getRow('link'));
        $this->assertStringEndsWith('chairperson/announcements', (string) db_connect()->table('notifications')->where('user_id', $this->p['ann']->id)->get()->getRow('link'));

        $audit = db_connect()->table('audit_logs')->where('action', 'college_announcement.posted')->get()->getRowArray();

        $this->assertSame('dean', $audit['acting_role']);
        $this->assertSame((string) $id, $audit['target_id']);
    }

    public function testAPersonReachedAsInstructorAndChairpersonIsToldOnce(): void
    {
        db_connect()->table('chairperson_assignments')->insert(['department_id' => $this->departmentA, 'chairperson_user_id' => $this->p['tess']->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);
        $id = $this->announce(['scope' => 'department:' . $this->departmentA]);

        $this->assertSame(1, db_connect()->table('notifications')->where(['user_id' => $this->p['tess']->id, 'dedupe_key' => "college-announcement:{$id}"])->countAllResults());
    }

    public function testAPostThatNobodyIsInSaysSoButIsKept(): void
    {
        $empty = DeanFactory::program(DeanFactory::department($this->college, 'Empty'), 'E');
        $result = $this->service->post($this->dean, ['scope' => 'program:' . $empty, 'audience' => 'everyone', 'title' => 'Hello', 'message' => 'Anyone?']);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Nobody is in that audience yet', $result['message']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badPosts(): iterable
    {
        yield 'no title' => [['title' => ' '], 'title'];
        yield 'title too long' => [['title' => str_repeat('a', 161)], 'title'];
        yield 'no message' => [['message' => ''], 'message'];
        yield 'message too long' => [['message' => str_repeat('a', 2001)], 'message'];
        yield 'a last day in the past' => [['ends_on' => '2020-01-01'], 'ends_on'];
        yield 'a last day that is not a date' => [['ends_on' => 'soon'], 'ends_on'];
        yield 'an audience that does not exist' => [['audience' => 'parents'], 'audience'];
        yield 'no scope' => [['scope' => ''], 'scope'];
        yield 'a scope that is made up' => [['scope' => 'program:abc'], 'scope'];
        yield 'a program that does not exist' => [['scope' => 'program:999999'], 'scope'];
        yield 'a department that does not exist' => [['scope' => 'department:999999'], 'scope'];
    }

    /** @param array<string, mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('badPosts')]
    public function testBadInputIsRefusedBesideTheFieldAndNothingIsStored(array $over, string $field): void
    {
        $result = $this->service->post($this->dean, $over + ['scope' => 'college', 'audience' => 'everyone', 'title' => 'T', 'message' => 'M']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey($field, $result['errors']);
        $this->assertSame(0, db_connect()->table('college_announcements')->countAllResults());
        $this->assertSame(0, db_connect()->table('notifications')->countAllResults());
    }

    public function testAnotherCollegesProgramOrDepartmentCannotBeAimedAt(): void
    {
        $foreignDepartment     = DeanFactory::department($this->otherCollege, 'Foreign');
        $foreignProgram = DeanFactory::program($foreignDepartment, 'F');

        foreach (["department:{$foreignDepartment}", "program:{$foreignProgram}"] as $scope) {
            $this->assertFalse($this->service->post($this->dean, ['scope' => $scope, 'audience' => 'everyone', 'title' => 'T', 'message' => 'M'])['success'], $scope);
        }

        $this->assertSame(0, db_connect()->table('college_announcements')->countAllResults());
    }

    public function testOnlyTheDeanOfACollegePosts(): void
    {
        $input = ['scope' => 'college', 'audience' => 'everyone', 'title' => 'T', 'message' => 'M'];
        $stray = PeopleFactory::person('dean', 'No', 'College', null, $this->institution);

        foreach ([$this->p['ann'], $this->p['tess'], $this->p['s1'], $stray] as $user) {
            $this->assertFalse($this->service->post($user, $input)['success']);
        }

        $this->assertSame(0, db_connect()->table('college_announcements')->countAllResults());
    }

    public function testEditingChangesTheWordingAndTheLastDayButNotWhoItIsFor(): void
    {
        $id = $this->announce(['scope' => 'department:' . $this->departmentA, 'audience' => 'students']);

        $result = $this->service->edit($this->dean, $id, ['title' => 'New title', 'message' => 'New words.', 'ends_on' => date('Y-m-d', strtotime('+3 days')), 'scope' => 'college', 'audience' => 'staff']);

        $this->assertTrue($result['success']);

        $row = $this->service->find($id);

        $this->assertSame('New title', $row['title']);
        $this->assertSame('New words.', $row['message']);
        $this->assertSame(date('Y-m-d', strtotime('+3 days')), $row['ends_on']);
        $this->assertSame('department', $row['scope_type'], 'who it is for does not change');
        $this->assertSame('students', $row['audience']);

        $audit = db_connect()->table('audit_logs')->where('action', 'college_announcement.edited')->get()->getRowArray();

        $this->assertSame('dean', $audit['acting_role']);
        $this->assertStringContainsString('Enrolment week', (string) $audit['old_values']);
        $this->assertStringContainsString('New title', (string) $audit['new_values']);
        $this->assertSame(2, db_connect()->table('notifications')->where('dedupe_key', "college-announcement:{$id}")->countAllResults(), 'the two students were told once, and editing tells nobody again');
    }

    public function testEditingIsRefusedWhenInvalidEndedOrNotYours(): void
    {
        $id    = $this->announce();
        $other = DeanFactory::dean($this->institution, $this->otherCollege, 'Eve');

        $this->assertFalse($this->service->edit($this->dean, $id, ['title' => '', 'message' => 'M'])['success']);
        $this->assertFalse($this->service->edit($other, $id, ['title' => 'Hijack', 'message' => 'M'])['success'], 'another college\'s dean');
        $this->assertFalse($this->service->edit($this->dean, 999999, ['title' => 'T', 'message' => 'M'])['success']);
        $this->assertSame('Enrolment week', $this->service->find($id)['title']);

        $this->service->end($this->dean, $id);

        $this->assertFalse($this->service->edit($this->dean, $id, ['title' => 'Late', 'message' => 'M'])['success'], 'an ended one cannot be edited');
    }

    public function testEndingStopsItAtOnceKeepsItAsHistoryAndAuditsIt(): void
    {
        $id = $this->announce();

        $this->assertTrue($this->service->end($this->dean, $id)['success']);
        $this->assertFalse($this->service->end($this->dean, $id)['success'], 'not twice');
        $this->assertSame([], $this->service->forUser($this->p['s1']));

        $row = $this->service->find($id);

        $this->assertSame('ended', $row['status']);
        $this->assertSame((int) $this->dean->id, (int) $row['ended_by']);
        $this->assertNotNull($row['ended_at']);
        $this->assertSame('dean', db_connect()->table('audit_logs')->where('action', 'college_announcement.ended')->get()->getRowArray()['acting_role']);
    }

    public function testAnotherCollegesDeanCannotEndOrSeeIt(): void
    {
        $id    = $this->announce();
        $other = DeanFactory::dean($this->institution, $this->otherCollege, 'Eve');

        $this->assertFalse($this->service->end($other, $id)['success']);
        $this->assertSame('active', $this->service->find($id)['status']);
        $this->assertSame([], $this->service->listForDean($other)['rows']);
    }

    public function testTheDeansListHasActiveAndEndedWithWhoItIsForAndHowManyItReaches(): void
    {
        $live  = $this->announce(['scope' => 'program:' . $this->programA1, 'audience' => 'students', 'title' => 'Live']);
        $gone  = $this->announce(['title' => 'Gone']);
        $past  = $this->announce(['title' => 'Past', 'ends_on' => date('Y-m-d')]);
        $this->service->end($this->dean, $gone);

        $active = $this->service->listForDean($this->dean);

        $this->assertSame(['active' => 2, 'ended' => 1], $active['counts']);
        $this->assertSame([$past, $live], array_map('intval', array_column($active['rows'], 'id')));

        $row = array_column($active['rows'], null, 'id')[$live];

        $this->assertSame('A · A1', $row['scope_label'] === 'Department A · Program A1' ? 'A · A1' : $row['scope_label']);
        $this->assertSame('Students', $row['audience_label']);
        $this->assertSame(1, $row['reach'], 'only s1 is a student in A1');
        $this->assertSame([$gone], array_map('intval', array_column($this->service->listForDean($this->dean, 'ended')['rows'], 'id')));

        $tomorrow = $this->service->listForDean($this->dean, 'ended', date('Y-m-d', strtotime('+1 day')));

        $this->assertEqualsCanonicalizing([$past, $gone], array_map('intval', array_column($tomorrow['rows'], 'id')), 'one past its last day counts as ended');
    }

    public function testTheFormOffersTheCollegeItsDepartmentsAndItsPrograms(): void
    {
        $options = $this->service->scopeOptions($this->dean);

        $this->assertSame('college', array_key_first($options));
        $this->assertSame('Whole College H', $options['college']);
        $this->assertArrayHasKey('department:' . $this->departmentA, $options);
        $this->assertArrayHasKey('program:' . $this->programB1, $options);
        $this->assertCount(1 + 2 + 3, $options);
        $this->assertSame([], $this->service->scopeOptions(PeopleFactory::person('dean', 'No', 'College', null, $this->institution)));
    }

    public function testBulkNotifyIsIdempotentAndSkipsWhoHasItAlready(): void
    {
        $notifications = new \App\Services\Notifications\NotificationService();
        $ids           = [(int) $this->p['s1']->id, (int) $this->p['s2']->id, (int) $this->p['s1']->id, 0];

        $this->assertSame(2, $notifications->sendToMany($ids, 'x', 'T', 'B', 'key-1', $this->institution, 'student/announcements'));
        $this->assertSame(0, $notifications->sendToMany($ids, 'x', 'T', 'B', 'key-1'), 'the same key is never stored twice');
        $this->assertSame(1, $notifications->sendToMany([(int) $this->p['s1']->id, (int) $this->p['s3']->id], 'x', 'T', 'B', 'key-1'), 'only the new person');
        $this->assertSame(0, $notifications->sendToMany([], 'x', 'T', 'B', 'key-2'));
        $this->assertSame(3, db_connect()->table('notifications')->where('dedupe_key', 'key-1')->countAllResults());
        $this->assertSame(0, (int) db_connect()->table('notifications')->where('dedupe_key', 'key-1')->selectSum('urgent')->get()->getRow('urgent'), 'never urgent, so never an email');
    }
}
