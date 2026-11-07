<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Admin\PermissionMatrix;
use App\Services\Messaging\ConversationService;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;

/**
 * A student and an instructor sharing an active course in one institution with
 * Messages switched on and a usable key, plus the conversation between them.
 * For the Messages route tests.
 */
trait MessagingScenario
{
    protected const MESSAGES_KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected int $institution;

    protected int $program;

    protected int $term;

    protected User $student;

    protected User $instructor;

    protected int $conversation;

    protected function buildScenario(): void
    {
        PermissionMatrix::flush();
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);

        $config                = config(Messages::class);
        $config->encryptionKey = self::MESSAGES_KEY;
        $config->previousKeys  = '';

        $d                 = DeanFactory::college('R1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::department($d['college'], 'RA'), 'RA');
        $this->term        = DeanFactory::term($this->institution);

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);
        $offering         = DeanFactory::offering($this->program, $this->term, 'RT1');
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);

        $this->conversation = (int) (new ConversationService())->startDirect($this->student, (int) $this->instructor->id)['id'];
    }

    protected function releaseScenario(): void
    {
        config(Messages::class)->encryptionKey = '';
        PermissionMatrix::flush();
    }

    protected function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
