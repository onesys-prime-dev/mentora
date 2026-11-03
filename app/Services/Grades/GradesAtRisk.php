<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\RosterRiskService;

/**
 * Students at risk across an instructor's sections, for either reason.
 *
 * By grade: failing so far, failed, or holding an incomplete (INC). These
 * come from stored gradebook rows and only for sections whose scheme is
 * published. By activity: silent or behind, by the same rule as the dashboard
 * (RosterRiskService), for every section. One row per student and section.
 * Two queries plus one RosterRiskService call, however many sections.
 */
final class GradesAtRisk
{
    public const KINDS = ['grade', 'inc', 'activity'];

    /**
     * @param list<array<string, mixed>> $sections rows of InstructorGradesOverview::sections()
     * @param string|null                $filter   grade | inc | activity | null (all)
     *
     * @return list<array{offering_id: int, student_id: int, name: string, section: string, slug: string, reasons: list<array{kind: string, text: string}>}>
     */
    public function forSections(array $sections, ?string $filter, ?string $today = null): array
    {
        if ($sections === []) {
            return [];
        }

        $today ??= date('Y-m-d');

        $byOffering = [];

        foreach ($sections as $section) {
            $byOffering[(int) $section['offering_id']] = $section;
        }

        $offeringIds = array_keys($byOffering);

        /** @var array<string, array{offering_id: int, student_id: int, name: string, reasons: list<array{kind: string, text: string}>}> $students */
        $students = [];

        $add = static function (int $offeringId, int $studentId, string $name, string $kind, string $text) use (&$students): void {
            $key = $offeringId . ':' . $studentId;

            $students[$key] ??= [
                'offering_id' => $offeringId,
                'student_id' => $studentId,
                'name' => $name,
                'reasons' => [],
            ];

            $students[$key]['reasons'][] = ['kind' => $kind, 'text' => $text];
        };

        $records = db_connect()->table('gradebook_records gr')
            ->select([
                'gr.course_offering_id', 'gr.student_id', 'gr.remarks', 'gr.is_override',
                'gr.overridden_percentage', 'gr.calculated_percentage', 'gr.finalized_at',
                'gr.final_percentage', 'gr.inc_set_at', 'gr.inc_until',
                'up.first_name', 'up.last_name',
            ])
            ->join('grading_schemes gs', 'gs.course_offering_id = gr.course_offering_id AND gs.status = ' . db_connect()->escape('Published'))
            ->join('enrollments e', 'e.offering_id = gr.course_offering_id AND e.student_user_id = gr.student_id')
            ->join('user_profiles up', 'up.user_id = gr.student_id')
            ->whereIn('gr.course_offering_id', $offeringIds)
            ->whereIn('e.status', ['enrolled', 'completed'])
            ->groupStart()
                ->where('gr.inc_set_at IS NOT NULL', null, false)
                ->orWhere('gr.remarks', 'Failed')
            ->groupEnd()
            ->get()
            ->getResultArray();

        foreach ($records as $record) {
            $offeringId = (int) $record['course_offering_id'];
            $studentId = (int) $record['student_id'];
            $name = trim($record['first_name'] . ' ' . $record['last_name']);

            if ($record['remarks'] === 'Failed') {
                $percentage = GradebookRecordModel::effectivePercentage($record);
                $label = empty($record['finalized_at']) ? 'Failing' : 'Failed';

                $add($offeringId, $studentId, $name, 'grade', $percentage === null ? $label : $label . ' · ' . number_format($percentage, 1) . '%');
            }

            if (!empty($record['inc_set_at'])) {
                $until = $record['inc_until'];

                $text = match (true) {
                    $until === null => 'INC',
                    $until < $today => 'INC · overdue',
                    default => 'INC · finish by ' . date('M j, Y', strtotime($until)),
                };

                $add($offeringId, $studentId, $name, 'inc', $text);
            }
        }

        foreach ((new RosterRiskService())->atRisk($offeringIds) as $row) {
            $text = $row['reason'] === 'inactive'
                ? ($row['days_since'] === null ? 'Never active' : 'Inactive ' . $row['days_since'] . ' days')
                : 'Behind on published work';

            $add((int) $row['offering_id'], (int) $row['student_id'], (string) $row['name'], 'activity', $text);
        }

        $rank = array_flip(self::KINDS);
        $rows = [];

        foreach ($students as $student) {
            if ($filter !== null && !in_array($filter, array_column($student['reasons'], 'kind'), true)) {
                continue;
            }

            usort($student['reasons'], static fn (array $a, array $b): int => $rank[$a['kind']] <=> $rank[$b['kind']]);

            $section = $byOffering[$student['offering_id']];

            $rows[] = $student + [
                'section' => $section['course_code'] . '-' . $section['section_code'],
                'slug' => (string) $section['slug'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aActivityOnly = $a['reasons'][0]['kind'] === 'activity';
            $bActivityOnly = $b['reasons'][0]['kind'] === 'activity';

            return [$aActivityOnly, $a['name'], $a['offering_id']] <=> [$bActivityOnly, $b['name'], $b['offering_id']];
        });

        return $rows;
    }
}
