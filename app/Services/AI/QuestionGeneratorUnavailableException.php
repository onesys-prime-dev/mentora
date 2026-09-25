<?php

namespace App\Services\AI;

/**
 * Thrown when the local question-builder service can't be reached at all,
 * or fails to produce usable output.
 */
class QuestionGeneratorUnavailableException extends \RuntimeException
{
}
