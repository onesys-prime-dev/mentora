<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\ClassScheduleService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * A section's Class times page (audit decisions 13.1, 13.2): its weekly meetings, with the clash warnings of
 * the last save; read-only while the SIS sends them.
 */
class OfferingScheduleController extends BaseController
{
    public function show(int $offeringId): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $schedule      = (new ClassScheduleService())->forOffering($institutionId, $offeringId);

        $offering = db_connect()->table('course_offerings o')
            ->select('o.id, o.section_code, o.status, c.course_code, c.course_title, t.name AS term_name, t.academic_year')
            ->join('courses c', 'c.id = o.course_id')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('o.id', $offeringId)
            ->get()->getRowArray() ?? throw PageNotFoundException::forPageNotFound();

        // After a refused save, the rows as they were typed (the blank ones are added again by the page).
        $typed = old('rows');

        if (is_array($typed)) {
            $typed = array_filter($typed, static fn ($r): bool => is_array($r)
                && implode('', array_map(static fn ($v): string => trim((string) $v), [$r['day'] ?? '', $r['start'] ?? '', $r['end'] ?? '', $r['room'] ?? ''])) !== '');
        }

        return view('pages/institution/offering-schedule', [
            'title'            => 'Class times',
            'page'             => 'offerings',
            'pageTitle'        => 'Class times',
            'user'             => auth()->user(),
            'scheduleOffering' => $offering,
            'scheduleRows'     => is_array($typed) ? array_values($typed) : $schedule['rows'],
            'scheduleSisOwned' => $schedule['sisOwned'],
            'scheduleReadOnly' => ! (new InstitutionAccess())->canWrite((int) auth()->id()),
            'scheduleCanEdit'  => ! $schedule['sisOwned'] && $offering['status'] === 'active' && (new InstitutionAccess())->canWrite((int) auth()->id()),
            'scheduleWarnings' => (array) (session('scheduleWarnings') ?? []),
        ]);
    }

    public function save(int $offeringId): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $rows          = $this->request->getPost('rows');
        $back          = 'institution/offerings/' . $offeringId . '/schedule';

        try {
            $result = (new ClassScheduleService())->save($institutionId, $offeringId, is_array($rows) ? array_values($rows) : [], auth()->user());
        } catch (StructureException $e) {
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('message', 'Class times saved.')->with('scheduleWarnings', $result['warnings']);
    }
}
