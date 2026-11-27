institution-wide messages

# End-to-End Feature Development Orchestration: Institution-Wide Messages

I want to rework who can message whom in the application's **Messages** feature. Today, Messages decides who may start a conversation from narrow relationship rules (shared courses, linked parents, assignments) and from each person's active role. I want to reassess this from the start. My direction is Microsoft Teams style: everyone inside one institution should be able to find and message each other, within sensible limits for safety, privacy and abuse. I do not want a quick patch that loosens a single rule. I want to restart discovery, reassess the requirements, rethink the experience and implement a complete, cohesive and maintainable result. If discovery shows the change is small, that's fine, but I still want every stage below.

This track was split out of the User Profile redevelopment. Read these before anything else:

- `docs/feature-development/user-profile/decision-register.md`, especially:
  - **D3:** institution-wide profile visibility.
  - **D4:** messaging scope stays out of User Profile and becomes this track.
  - **D18 / D20:** the avatar popover and modal with a Message button.
  - **D32:** the Message button follows `MessagingPolicy::canStart` and the active role until the single-identity track.
- `docs/superpowers/specs/2026-11-02-messages-design.md`, the current Messages spec. §3.1 is the scope this track amends.
- The separate **single identity + role screen redesign** track (`docs/feature-development/single-identity/`). It removes the role switcher and `active_role`. Coordinate with it: Messages must not deepen its dependence on the active role.

The known gap this track closes: after User Profile ships, a person can see a profile they cannot message.

Your responsibility is to orchestrate the entire lifecycle, from product discovery to implementation, using the appropriate Superpowers skills, specialized skills and subagents. Act as the primary orchestrator, product analyst, software architect, UX designer and development coordinator. I will make all product, architectural, design and implementation decisions. Do not make significant assumptions or finalize decisions on my behalf.

## Stage 1: Requirements Discovery (Grill-Me)

Start by invoking the `grill-me` skill. If it is unavailable, use `mattpocock-skills:grilling`, but ask exactly **one question per message**, never a batched round. Thoroughly investigate who should be able to message whom and how it should behave. Treat this as a fresh initiative. Do not assume the current rules are the desired ones.

Ask me **one question at a time**. With each question, give:

- thoughtful suggestions and alternative approaches;
- the advantages, disadvantages and trade-offs of each;
- the edge cases;
- your recommendation.

Make the alternatives strong enough that choosing between them is genuinely hard.

Explore all relevant aspects, including:

- who can start a conversation with whom, per role pair (student, instructor, chairperson, dean, institutional admin, parent, system admin);
- student-to-student direct messages;
- parent contact rules;
- group conversations and who can create them;
- the institution boundary, and people in more than one institution;
- people with several roles;
- deactivated people;
- read-only or suspended institutions;
- impersonation.

Explore safety too:

- blocking and muting;
- reporting;
- rate limits and spam;
- quiet hours, and the existing quiet roles (`MessagePreferences`);
- admin moderation, audit and retention;
- minors and parent visibility;
- what an institution can switch on or off.

Explore discovery:

- the people search or directory;
- starting a message from the profile popover or modal;
- suggested contacts;
- how existing conversations and preferences are kept.

Explore the UI/UX possibilities:

- new-message flows and recipient pickers;
- the empty, loading and error states;
- confirmation prompts and first-contact notices;
- responsive layouts;
- notification behaviour.

Do not rush this stage. Keep asking until the rules, workflows, permissions and expected behaviour are established. Record decisions as they are made and keep a list of open questions. Never silently assume an answer or overwrite an approved decision.

## Stage 2: Requirements Consolidation and Design (Superpowers Brainstorming)

After discovery, invoke `superpowers:brainstorming` to consolidate and refine the feature.

In this stage:

- Review all decisions.
- Identify inconsistencies, including with the User Profile decisions, and resolve ambiguities with me.
- Define functional and non-functional requirements.
- Set acceptance criteria.
- Identify dependencies, especially the single-identity track.
- Finalize the scope.

Produce UI/UX mockups covering every major interface and workflow for every relevant role. **For each major screen, show me two or three distinct mockup options and let me choose**, using the visual companion in the browser.

Evaluate the existing design system, layout conventions, component patterns and architecture before proposing designs. Use the `frontend-design` skill where appropriate.

This reworks an existing feature. Distinguish what should be retained, redesigned, replaced or removed. Do not assume existing behaviour is correct because it exists.

Do not proceed until I have reviewed and approved the requirements and designs.

## Stage 3: Implementation Planning (Superpowers Writing Plans)

Once approved, invoke `superpowers:writing-plans` to create detailed implementation plans divided into logical, manageable and independently verifiable phases.

Each phase must specify:

- objectives and tasks;
- affected modules and files;
- database changes;
- backend and frontend work;
- dependencies;
- authorization rules;
- data preservation;
- the testing strategy;
- acceptance criteria;
- deliverables.

Include an assessment of the current Messages implementation, including:

- `MessagingPolicy`;
- `MessagingContextResolver`;
- `MessagePreferences` and `ParentPreferences`;
- the Messages controllers and views;
- `resources/js` Messages code.

Identify what to reuse, refactor, replace or retire.

**Important:** reworking the rules does not mean deleting conversations, messages, attachments or preferences. Protect all existing data. Any destructive change, schema replacement or data migration must be planned, justified and approved by me.

Order the phases so each produces a working, testable increment. Present the complete plans for my approval before development.

## Stage 4: Implementation (Superpowers Subagent-Driven Development + TDD)

After approval, develop with `superpowers:subagent-driven-development` and `superpowers:test-driven-development`.

Work strictly from the approved plans, one phase at a time. Delegate independent tasks to subagents, review their output, and follow the project's architecture, conventions, security requirements and design system.

Write tests before the functionality. A phase is complete only when:

- its tests pass locally;
- regression testing is done;
- every acceptance criterion is met.

Pay particular attention to:

- authorization for every role pair;
- privacy, and the institution boundary;
- protection against harassment and spam;
- ownership of conversations;
- not exposing people a viewer must not reach.

Use `frontend-design` for frontend work when it helps.

Never skip failing tests, ignore unresolved issues, or proceed to another phase prematurely.

### Phase Completion and Git Workflow

After completing each implementation phase:

1. Run and verify all required tests locally.
2. Perform code review and validate the phase against its acceptance criteria.
3. Verify that existing conversations, messages, preferences and unrelated functionality remain intact.
4. Provide a summary of implemented functionality and modified components.
5. Report the tests executed and their results.
6. Identify any remaining limitations or considerations.
7. Provide a descriptive Git commit message for the completed phase.
8. Stop all development and wait for my explicit confirmation.

I will commit the changes myself. Do not create commits automatically.

Before creating the first branch, ask me which branch to base it on. The User Profile and single-identity tracks may be in progress.

Only proceed when I explicitly respond with **`done committing`**. After receiving confirmation, check out a new branch based on the previous phase's committed branch and begin the next phase.

Repeat until every phase is completed, tested, reviewed and committed. Then provide a comprehensive implementation summary. I will create and submit the final pull request to `main` through GitHub.

## Stage 5: Persistent Orchestration, Resume Capability, and Failure Recovery

This orchestration must survive interruptions, session closures, context limits and usage limits. Do not rely only on conversation history.

Create and maintain a persistent workspace in `docs/feature-development/messages-institution-wide/` containing:

- `ORCHESTRATION.md`: lifecycle, stage definitions, transition rules, approval gates.
- `progress.md`: current stage, phase, completed and pending tasks, active branch, latest verified checkpoint, exact next action.
- `requirements.md`: approved functional and non-functional requirements.
- `decision-register.md`: every decision, its status, rationale, alternatives and discussion references.
- `open-questions.md`: unresolved questions needing my input.
- `workflows.md`: role-based workflows.
- `ui-ux-design.md`: approved layouts, interface specifications and design decisions (with `mockups/`).
- `implementation-plan.md`: approved phased plans.
- `test-strategy.md`: testing approach, coverage, acceptance criteria and results.
- `existing-implementation-audit.md`: inventory of the current Messages permission and discovery implementation, with keep/replace/retire recommendations.

### Resume Capability

On every new or resumed session, first read these documents and the Git state. Determine:

- the last verified checkpoint;
- the stage;
- pending approvals;
- the branch;
- the next action.

Resume exactly where work stopped, without repeating or skipping work.

Before stopping or reaching a context limit, update the progress documents with:

- the verified state;
- outstanding tasks;
- decisions;
- exact resume instructions.

Never mark unfinished work complete.

### Decision Preservation

Keep a permanent decision register. If new information conflicts with an approved decision, here or in the User Profile register, flag it and consult me before changing anything. Never silently modify approved requirements, designs, rules or plans.

### Failure Recovery

If a test fails, a task errors, a subagent returns incomplete work, or a phase fails its acceptance criteria, stay in the current phase. Then:

1. Find the root cause.
2. Fix it.
3. Rerun the tests.
4. Verify before moving on.

Document failures and attempted fixes. Never report verification without real test results.

### Strict Approval Gates

These transitions always require my explicit approval:

- Requirements discovery → brainstorming and design.
- Brainstorming and design → implementation planning.
- Implementation planning → development.
- Each phase → the next, after I confirm `done committing`.

Never advance through these gates independently.

## General Orchestration Rules

- Use the installed Superpowers skills rather than imitating their workflows. Inspect available skills before invoking them. If one is missing, explain and propose an alternative.
- Use subagents strategically for research, implementation, testing and review.
- Keep the documentation persistent and synchronized with actual development.
- Do not make significant product or architectural decisions without consulting me.
- Prioritize security, privacy, maintainability, accessibility, usability and consistency with the existing application.
- Do not perform destructive database operations or remove existing functionality without explicit approval.
- Never proceed with failing tests or unresolved critical issues.
- Do not create or merge the final pull request.

Local environment note: XAMPP MySQL on :3306 is broken; do not repair it. PHP tests run on the scratch MariaDB on :3307:

```
env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit
```

See `docs/feature-development/group-activities/test-strategy.md`. Playwright may not run locally; record it as NOT RUN when so.

**Immediate instruction:** Begin Stage 1.

1. Inspect the current Messages implementation (frontend, backend, database, permission rules, preferences, notifications) and the User Profile decisions listed above.
2. Set up the persistent workspace and its resume and recovery mechanism.
3. Invoke `grill-me`.

Treat this as fresh product discovery. Ask me only one question at a time, with comprehensive suggestions and alternatives. I will make every product, design, architectural and implementation decision through our discussion.
