<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The feature flags that exist (SA7). A flag is defined here, in code, because
 * the code that checks it has to exist too; a system administrator turns
 * flags on and off, per institution or for everyone, but cannot invent one.
 *
 * Each flag has a description, and the default that applies when nothing else
 * says anything: not on a plan the institution has, not overridden for it,
 * and not changed globally. See App\Services\Flags\FeatureFlagResolver for the
 * full order.
 *
 * Keys are lower-case letters, numbers, dots, dashes and underscores. A plan
 * grants a flag by listing its key.
 */
class FeatureFlags extends BaseConfig
{
    /**
     * @var array<string, array{description: string, default: bool}>
     */
    public array $flags = [
        'sis.sync' => [
            'description' => 'Two-way sync with the institution\'s student information system: people, courses and enrolments in, final grades out.',
            'default'     => false,
        ],
        'ai.question_generation' => [
            'description' => 'AI-assisted question generation for quizzes.',
            'default'     => true,
        ],
    ];
}
