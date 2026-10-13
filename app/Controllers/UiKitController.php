<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * A gallery of the shared interface components (app/Views/components), every
 * one in every state, for the people building screens and the people reviewing
 * them. Signed-in people only, and not routed in production.
 *
 * The sample rows are invented here; nothing is read from or written to the
 * database. The posted forms answer in the way a real one does (JSON for the
 * dialog form), so the dialog behaviour can be tried end to end.
 */
class UiKitController extends BaseController
{
    public function index(): string
    {
        $sort = in_array($this->request->getGet('sort'), ['name', 'role'], true) ? (string) $this->request->getGet('sort') : 'name';
        $dir  = $this->request->getGet('dir') === 'desc' ? 'desc' : 'asc';

        return view('pages/ui-kit/index', [
            'title'      => 'Component gallery',
            'page'       => 'ui-kit',
            'pageTitle'  => 'Component gallery',
            'user'       => auth()->user(),
            'flashToasts' => true,
            // Explicit, so view data left by another page cannot change it (it is also the layout's default).
            'mainGutter'  => 'px-4 sm:px-8 lg:px-16 xl:px-40',
            'sort'       => $sort,
            'dir'        => $dir,
        ]);
    }

    /** What a dialog form posts to: a refusal for an empty name, a success otherwise. */
    public function submit()
    {
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setStatusCode(422)->setJSON(['errors' => ['name' => 'Enter a name.'], 'message' => 'Nothing was saved.']);
        }

        return $this->response->setJSON(['message' => "{$name} was saved.", 'redirect' => site_url('ui-kit')]);
    }
}
