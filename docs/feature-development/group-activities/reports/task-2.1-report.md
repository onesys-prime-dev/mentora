# Task 2.1 report
Status: DONE. Uncommitted.
Files: app/Services/Groups/GroupGeneratorService.php (new; includes small SeededShuffle xorshift class), app/Services/Groups/GroupSetService.php (saveGenerated), tests/database/GroupGeneratorServiceTest.php, tests/database/GroupGeneratorSaveTest.php.
Red: "Tests: 16, Assertions: 0, Errors: 16" (class not found).
Green: "Tests: 48, Assertions: 380" (filter GroupGenerator|GroupSetServiceTest|GroupMembershipServiceTest|GroupSchemaTest|GroupSetsRoutesTest).
Notes: preview ignores 'min'; by-size also refuses when groups > 50; saveGenerated validates fully before the transaction and rolls back on DB failure; groups deleted via raw table deletes.
