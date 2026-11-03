<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Admin\PermissionMatrix;
use App\Services\Messaging\MessageCipher;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;

/**
 * One institution with a child enrolled in a course taught by an instructor,
 * the child's parent (linked), and the chairperson of the child's sub-department.
 * For the parent Messages tests.
 */
trait ParentScenario
{
    protected const PARENT_KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected int $institution;

    protected int $department;

    protected int $subDepartment;

    protected int $program;

    protected int $term;

    protected int $offering;

    protected User $child;

    protected User $parent;

    protected User $instructor;

    protected User $chair;

    protected function buildParentScenario(string $tag = 'PS'): void
    {
        $d                   = DeanFactory::department($tag);
        $this->institution   = $d['institution'];
        $this->department    = $d['department'];
        $this->subDepartment = DeanFactory::subDepartment($d['department'], $tag . 'S');
        $this->program       = DeanFactory::program($this->subDepartment, $tag . 'P');
        $this->term          = DeanFactory::term($this->institution);
        $this->offering      = DeanFactory::offering($this->program, $this->term, $tag . 'C1');

        $this->child      = PeopleFactory::person('student', 'Kid', 'Child', null, $this->institution);
        $this->parent     = PeopleFactory::person('parent', 'Pat', 'Parent', null, $this->institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);
        $this->chair      = DeanFactory::chair($this->subDepartment);

        CourseFactory::enroll($this->offering, $this->child);
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->linkParent($this->parent, $this->child);
    }

    protected function linkParent(User $parent, User $child): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function unlinkParent(User $parent, User $child): void
    {
        db_connect()->table('parent_student_links')->where(['parent_user_id' => $parent->id, 'student_user_id' => $child->id])->delete();
    }

    /** Messages switched on and a usable key, as MessagingScenario does. */
    protected function enableMessages(): void
    {
        PermissionMatrix::flush();
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);

        $config                = config(Messages::class);
        $config->encryptionKey = self::PARENT_KEY;
        $config->previousKeys  = '';
    }

    protected function releaseMessages(): void
    {
        config(Messages::class)->encryptionKey = '';
        PermissionMatrix::flush();
    }

    protected function parentCipher(): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = self::PARENT_KEY;
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }
}
