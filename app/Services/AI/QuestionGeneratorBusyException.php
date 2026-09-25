<?php

namespace App\Services\AI;

/**
 * Thrown when the local question-builder service already has a generation
 * job running (single shared GPU — no concurrent generation).
 */
class QuestionGeneratorBusyException extends \RuntimeException
{
}
