<?php

declare(strict_types=1);

use App\Services\Messaging\RecipientSearch;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The new-message picker: only people the policy lets this person start with,
 * found by name and by nothing else.
 *
 * @internal
 */
final class RecipientSearchTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $program;

    private int $term;

    private RecipientSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::college('Q1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::department($d['college'], 'QA'), 'QA');
        $this->term        = DeanFactory::term($this->institution);
        $this->search      = new RecipientSearch();
    }

    private function person(string $role, string $first, string $last, ?int $institution = null, bool $active = true, ?string $email = null): User
    {
        return PeopleFactory::person($role, $first, $last, $email, $institution ?? $this->institution, $active);
    }

    /** @return array{User, User, int} a student and an instructor of one active course, and the offering */
    private function pair(string $code = 'QC1'): array
    {
        $student    = $this->person('student', 'Stella', 'Student');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius');
        $offering   = DeanFactory::offering($this->program, $this->term, $code);
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);

        return [$student, $instructor, $offering];
    }

    /** @return list<int> */
    private function ids(User $sender, string $query): array
    {
        return array_map(static fn (array $r): int => $r['id'], $this->search->search($sender, $query));
    }

    public function testAStudentFindsTheInstructorOfASharedCourseByName(): void
    {
        [$student, $instructor] = $this->pair();

        $found = $this->search->search($student, 'Ignat');

        $this->assertCount(1, $found);
        $this->assertSame((int) $instructor->id, $found[0]['id']);
        $this->assertSame('Ivan Ignatius', $found[0]['name']);
        $this->assertSame('instructor', $found[0]['role']);
        $this->assertNull($found[0]['subject_student_id']);
    }

    public function testNobodyElseIsListedForAStudent(): void
    {
        [$student] = $this->pair();
        $this->person('chairperson', 'Chad', 'Zed');
        $this->person('dean', 'Dana', 'Zed');
        $this->person('parent', 'Pia', 'Zed');
        $this->person('instructor', 'Ian', 'Zed');                // teaches nothing shared
        $this->person('instructor', 'Ila', 'Zed', null, false);   // deactivated
        $this->person('admin', 'Ada', 'Zed');
        $other = DeanFactory::college('Q2');
        $this->person('instructor', 'Ola', 'Zed', $other['institution']);

        $this->assertSame([], $this->ids($student, 'Zed'));
        $this->assertSame([], $this->ids($student, 'Stella'), 'not oneself');
    }

    public function testABlockedRecipientIsNotListedButAnInstructorStillIs(): void
    {
        [$student, , $offering] = $this->pair('QC2');
        $classmate              = $this->person('student', 'Clara', 'Classmate');
        CourseFactory::enroll($offering, $classmate);

        $this->assertNotSame([], $this->ids($student, 'Clara'));

        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $classmate->id, 'blocked_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->ids($student, 'Clara'));
        $this->assertNotSame([], $this->ids($student, 'Ignatius'));
    }

    public function testAQueryUnderTwoCharactersFindsNothing(): void
    {
        [$student] = $this->pair();

        $this->assertSame([], $this->ids($student, 'I'));
        $this->assertSame([], $this->ids($student, ' '));
    }

    public function testEmailAndPhoneAreNotSearchable(): void
    {
        $student    = $this->person('student', 'Stella', 'Student');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius', null, true, 'ivan.secret@example.com');
        $offering   = DeanFactory::offering($this->program, $this->term, 'QC3');
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);

        $this->assertSame([], $this->ids($student, 'ivan.secret'));
        $this->assertSame([], $this->ids($student, '@example.com'));
    }

    public function testAParentGetsOneEntryPerSharedChild(): void
    {
        $parent     = $this->person('parent', 'Pat', 'Parent');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius');
        $offering   = DeanFactory::offering($this->program, $this->term, 'QC4');
        CourseFactory::assignInstructor($offering, $instructor);

        foreach (['Ann', 'Ben'] as $first) {
            $child = $this->person('student', $first, 'Kid');
            CourseFactory::enroll($offering, $child);
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $found = $this->search->search($parent, 'Ignatius');

        $this->assertCount(2, $found);
        $this->assertSame(['Ann Kid', 'Ben Kid'], array_column($found, 'subject_name'));
        $this->assertNotNull($found[0]['subject_student_id']);
    }
}
