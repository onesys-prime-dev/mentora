<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Admin\Impersonation;
use App\Services\Institutions\StructureException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * An institution's administrator viewing the product as one of its own
 * people, to see what they see. The route decides who may (the permission and
 * the password); Impersonation decides who can be viewed, makes the view
 * read-only and time-limited, and keeps the record. Leaving is the public
 * `impersonation/stop`, since while viewing the session is the other
 * person's.
 */
class ViewAsController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/people';

    public function start(int $id): ResponseInterface|RedirectResponse
    {
        $reason = (string) ($this->input()['reason'] ?? '');

        return $this->run(
            function () use ($id, $reason): void {
                try {
                    (new Impersonation())->start(auth()->user(), $id, $reason);
                } catch (StructureException $e) {
                    throw $e;
                } catch (InvalidArgumentException $e) {
                    // The reason is the only thing the person can put right; the rest is said as it is.
                    throw str_starts_with($e->getMessage(), 'Say why') ? StructureException::field('reason', $e->getMessage()) : new StructureException($e->getMessage());
                }
            },
            'You are viewing as this person. It is view only: nothing you do is saved.',
            self::BACK,
            '/',
        );
    }
}
