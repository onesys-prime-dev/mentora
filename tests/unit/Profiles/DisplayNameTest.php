<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * person_display_name(): the social name (FR5) is the preferred first name and the legal last name.
 *
 * @internal
 */
final class DisplayNameTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('navigation');
    }

    public function testAPreferredNameReplacesTheFirstNameOnly(): void
    {
        $this->assertSame('Jun Dela Cruz', person_display_name(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']));
    }

    public function testNoPreferredNameGivesTheLegalName(): void
    {
        $this->assertSame('Juan Dela Cruz', person_display_name(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => null]));
        $this->assertSame('Juan Dela Cruz', person_display_name(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => '   ']));
        $this->assertSame('Juan Dela Cruz', person_display_name(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']));
    }

    public function testPartsAreTrimmed(): void
    {
        $this->assertSame('Jun Dela Cruz', person_display_name(['first_name' => ' Juan ', 'last_name' => ' Dela Cruz ', 'preferred_first_name' => ' Jun ']));
        $this->assertSame('Dela Cruz', person_display_name(['first_name' => '', 'last_name' => 'Dela Cruz']));
        $this->assertSame('', person_display_name([]));
    }
}
