# Task 1.2 report
Status: DONE_WITH_CONCERNS. Files: app/Services/Groups/GroupSetService.php, GroupMembershipService.php, tests/database/GroupSetServiceTest.php, GroupMembershipServiceTest.php.
Exception: reused App\Services\Institutions\StructureException (message + field=>message errors()). Finalized message is GradeFinalizationService::LOCKED_MESSAGE via lockRefusal.
Shared static helpers GroupSetService::refuseIfFinalized() and ::audit() are used by both services. Audit actor is loaded from the user id via auth()->getProvider().
addToSet/moveInSet lock the target group row (SELECT FOR UPDATE) and run in a transaction; validation happens before the delete in a move.
Extra tests beyond the list: pool, remove, deleteGroup, 50-group limit, validation of name/mode/window.
Concerns: tests and code were written together (no separate red run). The nested-transaction rollback path inside the test isolation transaction is not exercised (move failure is tested via the full-target refusal).
