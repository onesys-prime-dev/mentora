<?php
/**
 * The group sets of the course, one row each; the name opens the set beside the list.
 *
 * @var list<array<string, mixed>> $groupSets
 * @var int $poolSize
 */
$modes = ['manual' => 'Manual', 'auto' => 'Auto-generated', 'self_signup' => 'Self-signup', 'proposed' => 'Proposed by students'];

$rows = array_map(static function (array $set) use ($modes, $poolSize): array {
    $id     = (int) $set['id'];
    $groups = (int) $set['group_count'];
    $below  = (int) $set['below_min'];

    return [
        'id'    => $id,
        'label' => (string) $set['name'],
        'cells' => [
            'name' => ['html' => '<button type="button" class="rounded text-left font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11" data-group-set-open="' . $id . '">' . esc((string) $set['name']) . '</button>'],
            'mode'   => ['html' => view('components/status-badge', ['label' => $modes[$set['formation_mode']] ?? 'Manual', 'tone' => 'info'], ['saveData' => false])],
            'groups' => ['html' => '<span data-set-groups="' . $id . '">' . $groups . ($groups === 1 ? ' group' : ' groups') . ($below > 0 ? ' · ' . $below . ' below minimum' : '') . '</span>'],
            'placed' => ['html' => '<span data-set-placed="' . $id . '">' . (int) $set['member_count'] . ' of ' . $poolSize . '</span>'],
        ],
    ];
}, $groupSets);
?>

<?= view('components/data-table', [
    'caption'   => 'Group sets of this course',
    'columns'   => [
        ['key' => 'name', 'label' => 'Set'],
        ['key' => 'mode', 'label' => 'Mode'],
        ['key' => 'groups', 'label' => 'Groups'],
        ['key' => 'placed', 'label' => 'Students placed'],
    ],
    'rows'      => $rows,
    // Stacked cards on a phone: the row sheet machinery is for management lists with row menus.
    'phoneRows' => false,
], ['saveData' => false]) ?>
