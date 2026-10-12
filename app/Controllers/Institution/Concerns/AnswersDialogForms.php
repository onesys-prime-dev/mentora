<?php

declare(strict_types=1);

namespace App\Controllers\Institution\Concerns;

use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * How the institutional administrator's management pages answer a change
 * made from a form in a dialog (or a plain form): JSON for the dialog's
 * script, a redirect with a message otherwise, so every page also works
 * without JavaScript.
 *
 * - success: JSON {message, warnings, redirect} (the message is also flashed
 *   for the page the script goes to) or a redirect with the message. A change
 *   that returns warnings (a list of strings, or ['warnings' => [...]]) has
 *   them added to the message;
 * - refusal (StructureException): 422 {errors, message} with the field to
 *   highlight, or a redirect with the reason;
 * - a foreign id is the service's 404, and is not caught here.
 */
trait AnswersDialogForms
{
    /**
     * @param callable(int): mixed $change given the administrator's institution id
     */
    private function run(callable $change, string $success, string $backTo): ResponseInterface|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $wantsJson     = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back          = site_url($backTo);

        try {
            $result = $change($institutionId);
        } catch (StructureException $e) {
            if ($wantsJson) {
                return $this->response->setStatusCode(422)->setJSON(['errors' => $e->errors(), 'message' => $e->errors() === [] ? $e->getMessage() : 'Nothing was saved.']);
            }

            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        // A change may hand back warnings (it went through, but the person should know something).
        $warnings = [];

        if (is_array($result)) {
            $candidate = $result['warnings'] ?? $result;
            $warnings  = array_values(array_filter(is_array($candidate) ? $candidate : [], 'is_string'));
        }

        $message = trim($success . ' ' . implode(' ', $warnings));

        if ($wantsJson) {
            // The dialog's script goes to the list next; the message is waiting there.
            session()->setFlashdata('message', $message);

            return $this->response->setJSON(['message' => $message, 'warnings' => $warnings, 'redirect' => $back]);
        }

        return redirect()->to($back)->with('message', $message);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $post = $this->request->getPost();

        return is_array($post) ? $post : [];
    }
}
