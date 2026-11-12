<?php
declare(strict_types=1);

namespace FlowDesk;

final class WorkflowException extends \DomainException
{
}

/**
 * Pure state machine: which stage changes exist, who may make them, and when a note is mandatory.
 * Contains no I/O, so it is trivial to test.
 */
final class Workflow
{
    public const STAGES = [
        'new'      => 'New',
        'survey'   => 'Field survey',
        'drafting' => 'Drafting',
        'review'   => 'Review',
        'done'     => 'Completed',
        'hold'     => 'On hold',
    ];

    public const ROLES = [
        'manager'  => 'Manager',
        'intake'   => 'Intake',
        'surveyor' => 'Surveyor',
        'drafter'  => 'Drafter',
    ];

    /** @var array<string, array{from: list<string>, to: ?string, roles: list<string>, label: string, note: bool, style: string}> */
    private const ACTIONS = [
        'start_survey'  => ['from' => ['new'],      'to' => 'survey',   'roles' => ['surveyor', 'manager'], 'label' => 'Start survey',        'note' => false, 'style' => 'primary'],
        'finish_survey' => ['from' => ['survey'],   'to' => 'drafting', 'roles' => ['surveyor', 'manager'], 'label' => 'Survey complete',     'note' => false, 'style' => 'primary'],
        'submit_review' => ['from' => ['drafting'], 'to' => 'review',   'roles' => ['drafter', 'manager'],  'label' => 'Submit for review',   'note' => false, 'style' => 'primary'],
        'approve'       => ['from' => ['review'],   'to' => 'done',     'roles' => ['manager'],             'label' => 'Approve',             'note' => false, 'style' => 'success'],
        'reject'        => ['from' => ['review'],   'to' => 'drafting', 'roles' => ['manager'],             'label' => 'Send back',           'note' => true,  'style' => 'warn'],
        'hold'          => ['from' => ['new', 'survey', 'drafting', 'review'], 'to' => 'hold', 'roles' => ['manager'], 'label' => 'Put on hold', 'note' => true, 'style' => 'ghost'],
        'resume'        => ['from' => ['hold'],     'to' => null,       'roles' => ['manager'],             'label' => 'Resume',              'note' => false, 'style' => 'primary'],
    ];

    /** @return array<string, array{label: string, note: bool, style: string}> actions this role may take from this stage */
    public static function available(string $stage, string $role): array
    {
        $out = [];
        foreach (self::ACTIONS as $key => $a) {
            if (in_array($stage, $a['from'], true) && in_array($role, $a['roles'], true)) {
                $out[$key] = ['label' => $a['label'], 'note' => $a['note'], 'style' => $a['style']];
            }
        }
        return $out;
    }

    /**
     * Validate an action and compute the resulting state.
     *
     * @param array{stage: string, held_from?: ?string} $order
     * @return array{stage: string, held_from: ?string}
     */
    public static function apply(array $order, string $action, string $role, ?string $note): array
    {
        $a = self::ACTIONS[$action] ?? throw new WorkflowException('Unknown action.');
        if (!in_array($order['stage'], $a['from'], true)) {
            throw new WorkflowException('This action is not possible from the current stage.');
        }
        if (!in_array($role, $a['roles'], true)) {
            throw new WorkflowException('Your role is not allowed to perform this action.');
        }
        if ($a['note'] && trim((string) $note) === '') {
            throw new WorkflowException('A note is required for this action.');
        }

        if ($action === 'hold') {
            return ['stage' => 'hold', 'held_from' => $order['stage']];
        }
        if ($action === 'resume') {
            $back = $order['held_from'] ?? null;
            if ($back === null || !isset(self::STAGES[$back]) || $back === 'hold') {
                throw new WorkflowException('Order has no stage to resume to.');
            }
            return ['stage' => $back, 'held_from' => null];
        }
        return ['stage' => (string) $a['to'], 'held_from' => null];
    }
}
