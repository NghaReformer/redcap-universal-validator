# Changelog

## 1.9.7 - the cancel that never finished

Pressing Stop left the run in `cancelling`, and there it stayed.

The phase is a deliberate two-step: bump the lease epoch, THEN write the
terminal state, so a worker already evaluating fails its compare-and-set instead
of committing into a finished run. That is the whole reason `cancelling` exists.
But nothing owned the second step - the worker refuses to work in a phase that
takes no work, and returned early without finishing it - so the run stayed
active, holding the project's one scan slot, until the abandoned-run sweep
caught it hours later. Stop then Start reported the project busy with the user's
own cancelled run.

Whoever arrives next now finishes it. `finish()` refuses to reopen anything, so
two workers landing on the same cancelled run is not a race. And `start()` reaps
it before asking for the slot, because the person starting a new scan does not
have a run id to hand the worker - reaping only in the worker would have fixed
the case nobody hits and left the one everybody hits.

Found the same way as the last four: by watching a real run instead of a test.

## 1.9.6 - a run that walked past the records it never examined

The scan ran. On real data, on a real server, it examined records and wrote
1,815 findings. Then it reached its last phase with **3 of 39 records done** and
answered `scan-work` with `done: true, worked: 0`.

Two faults compounded, and the second is the one that matters.

**An empty claim meant two different things.** `claim()` and `claimPending()`
both returned `[]` for "this run has nothing more to hand out" AND for "you may
not claim right now" - a cancelled run, a moved epoch, a phase that changed
underneath, a read that failed. The first is a reason to move on; the second is
a reason to stop. They are now `[]` and `false`, and the contract test asserts
the two are distinguishable by `===` rather than merely both falsy, because
both-falsy is exactly how they got conflated.

**And the worker advanced on an empty claim without asking whether the manifest
was finished.** It now refuses to leave any phase but `scanning` while a record
remains non-terminal, and says which: records still held by another worker, or
left behind by one that stopped. Scanning keeps its exception - its cursor can
legitimately reach the end while rows sit claimed - which is precisely why
catch-up, which sweeps those stragglers by state, may not advance past them.

`done` is now a predicate over record states rather than the fact that the loop
ran out of work. It was the loop's own exhaustion, which is a statement about
the worker and not about the project.

Coverage would still have read `partial`, so the run could never have claimed to
be complete - the safety net held, and that is the only reason this was a bug
about wasted work rather than a false clean bill of health. It still abandoned
36 records it could have examined and told the client it was finished.

Verified by mutation: conflating the two answers again fails three checks. The
first attempt at that mutation silently did not apply and reported a clean pass,
which is its own reminder that an unverified mutation test proves nothing.

**Also: a version row is not evidence that the tables exist.** Cleaning up after
an interrupted test run left `uv_schema_version` standing over dropped tables,
and `migrate()` read "already at version 1" and did nothing - forever - while
`health()` correctly called the schema broken. The same accident is a database
restore. It now asks the facts rather than the flag, and re-applies; every
statement is CREATE TABLE IF NOT EXISTS, so being right costs one no-op per
table. A probe that cannot answer still changes nothing.

## 1.9.5 - the pool nothing filled

Past the rights gates, the scan planned. It authorized, resolved the entitlement
forms, computed a fingerprint, captured an opening fence and froze a 39-record
manifest from the real record list - all of it correct on the first attempt.

Then it did nothing at all, and said: "This server is busy with other scans;
this one will continue shortly." There were no other scans.

`WorkerSlots::provision()` had no caller anywhere outside its own tests - the
same shape as the migration in 1.9.1. Leasing a slot is an UPDATE against
precreated rows, deliberately, so two racing workers are serialised by InnoDB
and exactly one sees a row changed. The consequence is that **the count of rows
is the limit**, and a table with no rows is a limit of zero. Creating the table
does not create the rows, and nothing created the rows.

Provisioning now happens where the schema is installed, which is the same
administrator action: ticking the switch and pressing Save. It is additive, so
raising the limit adds rows on the next save and lowering it never deletes one -
a row being deleted may be leased right now.

**And the message was false, which is the worse half.** Two different faults are
indistinguishable at the `acquire()` call, and only one of them is contention.
Telling an administrator the server is busy when the pool is empty sends them
looking for scans that do not exist. One extra query, on the failure path only,
now tells an empty pool from a full one, and the empty case says how to fix it.

Both are checked against real servers rather than a mock: a real store, real
slots and an empty pool, asserting the worker stops with `unprovisioned`, does
no work, marks nothing examined - and that provisioning one slot lets the same
run proceed, so the refusal really was about the pool. 275 checks across MySQL
5.7/8.0 and MariaDB 10.5/10.11.

## 1.9.4 - the administrator has no rights row, and never needed one

The diagnostic added in 1.9.3 answered its question on the first click, and my
guess in 1.9.3 was wrong. The refusal came back:

> no export level was present in your rights for this project (this build
> supplied neither data_export_tool nor data_export)

Neither key. Not an API-shape mismatch at all - the account is a REDCap
super-user, and a super-user bypasses project rights entirely, so there is no
`redcap_user_rights` row to read. The framework hands back design rights and
nothing else, and every gate here read that absence as "no rights" and refused
the one category of user who can already export the whole project from REDCap's
own exporter.

It was two gates, not one, and they presented as one defect: the export level
was missing, and so was the per-instrument map, so even past the export check
every instrument would have been barred.

**Asked, never inferred from absence.** This is the line that keeps the fix from
being a hole. An account with no export level is still refused exactly as
before; what changed is that the module now ASKS the framework whether this is
an administrator, through `is_callable` - never `method_exists`, which answers
false for anything served via `__call()` and is how v1.4.0 shipped a dead
feature. Only an affirmative answer counts.

**Resolution, not invention.** An administrator with an explicit rights row
keeps whatever it says: a deliberate restriction somebody typed outranks an
inference we made. An explicitly de-identified administrator still may not start
a run. An unreadable rights shape stays unreadable, administrator or not.

The inverse assertions are the ones that matter, and they are checked by
mutation: making absence grant rights fails three of them. 163 checks in the
security suite.

## 1.9.3 - one reader for the export level, and a refusal that diagnoses itself

Third pilot finding. With the transport fixed, Start reached the server, the
framework authenticated it, and authorization refused: "this scan stores record
values, so it needs Full Data Set export rights; your account does not have
them" - to an account REDCap's own User Rights page shows as Full Data Set.

That message gave nobody a way to tell a wrong RIGHT from a wrong READING of
one, so the first change is that it now says what it actually read: the level by
name, or that no level was present and under which keys it looked. A user is
being told about their own export rights, which they can already see on their
own User Rights page, so this discloses nothing - and it is the difference
between "ask your administrator" and a fix.

The second change is the likely cause and a real robustness gap either way. The
level was read only as `data_export_tool`, the column in `redcap_user_rights`.
REDCap's own API payloads carry the same value as `data_export`, and a build
handing back that shape read as no export rights at all - a denial
indistinguishable from a correctly refused user. Both names are now read, the
stored column wins when both are present, and an absent level is still a denial
because guessing a level from an absent one fails open.

**One reader, three callers, converted together.** The export level decides the
value ceiling, whether the file may be downloaded, and whether a run may start;
three separate copies of the same array lookup is three chances for one to be
reading the wrong key - and it would show up as a user refused a run and granted
a raw-value export in the same request. `ScanPageView::exportLevel()` is now the
only place that knows where the level lives. The recorded lesson from the v1.6.0
rounds is that a shared helper has to arrive with every caller already
converted, so it does.

151 checks in the security suite, green on PHP 7.4 and 8.4.

## 1.9.2 - a panel nothing could drive

Second defect from the same pilot, one click after the first. With the schema
installed the panel rendered, and pressing Start threw `Cannot read properties
of undefined (reading 'UniversalValidator')` before any request left the
browser.

The page printed the framework's JavaScript module object NAME without ever
emitting the bootstrap that creates it. `ExternalModules` existed and held
exactly one key; the module's own namespace was never built, because
`initializeJavascriptModuleObject()` had not been called. The data-entry path
has always done this properly - guarding with `is_callable`, supporting both the
echo and return conventions, and logging a diagnosis when the transport is
missing - and the scan page did none of it.

**Why no test caught it.** Both switches default off, so every page scenario
ever written rendered the UNAVAILABLE branch. The branch people will actually
use had zero coverage, and the mocked client test supplied `UVScan.ajax`
directly, so the real bootstrap was never exercised from either side. Fifteen
new scenarios now render the panel, and two of them assert the bootstrap appears
BEFORE the name it defines - verified by deleting it and watching them fail.

**And a page that cannot drive a scan no longer offers one.** A build with no
transport, or one that throws while starting it, now shows the unavailable
notice naming the missing piece, rather than a Start button that cannot work.
That is the same rule the rest of the module follows: a control that refuses is
an invitation to file a bug.

Both pilot defects are the same shape and neither was findable in CI: the first
was a migration nothing called, the second a transport nothing started. Both
were caught in the first ten minutes of running it on a real server, which is
what the pilot gate is for.

## 1.9.1 - the tables nothing created

The first live pilot of 1.9.0 found a defect the whole test suite was green
over: **`Schema::migrate()` had no caller.** The migration was written, the
health check was written, and nothing anywhere invoked the one to satisfy the
other. With both switches on, the scan page correctly reported "10 table(s) are
missing; the durable scan stays disabled" — and would have reported it forever.

Every scan test built its tables directly, which is why 836 checks passed over a
feature that could not install itself. That is the v1.4.0 shape exactly: a
production-inert feature behind a green suite, found only by running it on a
real server. It is also the argument for the pilot gate, made a second time.

**The trigger is the administrator's explicit action, not a page view.** Ticking
the installation-wide switch and pressing Save is the choice, so
`redcap_module_save_configuration()` installs the schema, and
`redcap_module_system_enable()` covers the reinstall and upgrade paths that
never pass through a settings save. A project saving its own settings installs
nothing: the schema is an installation-level object, and a project administrator
is not the person who decides the database gains ten tables.

Nothing is installed while the switch is off, so an administrator who never
asked for the feature never gets its tables.

**A migration that fails must not fail the save.** It runs inside the settings
hook, so a throw would tell an administrator their settings could not be stored
— wrong, and unactionable. The failure is swallowed there and reported where it
is legible: the scan page names the missing tables, and the attempt is written
to the module log either way.

Seven new checks in `tests/hosting_php.php`, verified by removing the trigger
and watching two of them fail.

## 1.9.0 - the scan runs again, behind a switch

Task 6 of the rebuild plan is complete. The durable scan has entrypoints, a
client, and two switches that are both off.

**Four AJAX verbs, in `auth-ajax-actions` only.** `scan-start`, `scan-work`,
`scan-status`, `scan-cancel`. A scan reads and stores record values, so there is
no version of it an unauthenticated caller may reach. Each verb re-checks the
signed-in user itself rather than trusting the framework's action list, because
`redcap_module_ajax()` guards the action NAME and hands the caller's identity
straight through without checking it.

**`ScanService` is where the fourteen classes are assembled.** Each of them
knows one thing and none of them know how to find the others; if that wiring
lived in the AJAX handler then the AJAX handler would be the design. The handler
is now nine lines that read a run id and call one of four methods.

**One seam into the module.** `durableScanContext()` hands the durable side
closures and nothing else, so `php/Scan/` never learns what a data dictionary
is. `scanPlan()` and `scanRecord()` stay private, which also means the legacy
synchronous path and the durable one cannot drift into two ideas of what a rule
means — they run the same two methods.

**`ReasonCode` makes the reason a column.** `assert:` embeds the whole
assertion, up to 507 characters, in every finding that rule produces; that is a
property of the rule, and per-finding it is up to a gigabyte of one repeated
sentence that also destroys the index and the `GROUP BY` the summary needs. The
code is now the kind, and `pooled:` becomes a five-bit mask over a closed set.
An unknown reason passes through truncated and marked, never dropped: a future
rule type must degrade to generic wording, not to a silent hole.

**`js/scan.js` drives a loop and decides nothing.** It sends a run id and
nothing else; the server re-derives the project, the user, the group and the
entitlement on every request. A refused batch ends the loop and says why, a busy
server is a wait rather than a failure, a dropped connection retries because the
run on the server is untouched — and completion comes from the server saying
terminal, never from the counts looking finished. Catch-up, the duplicate
finalizer and the summary all run after the last record, so a client that
stopped at `done === total` would call a scan finished while it was still
deciding whether two records share a hospital number.

**Two switches, both off.** A system administrator enables the durable scan for
the installation and a project enables it for itself; either off and the page
shows the notice it has shown since Task 1. `docs/TESTING.md` gains the pilot
checklist that has to pass before either is turned on anywhere — closing the tab
mid-scan, two tabs racing, editing and deleting records while it runs, and the
numbers to record. A flag that defaults on is not a flag, and the reason this
one exists is v1.4.0: a production-inert `@UVUNIQUE` shipped with every mocked
test green, because the framework serves some methods through `__call()`.

The page renders the run's state before any script runs, so somebody with
scripting disabled sees whether their scan is going rather than an empty panel.

836 checks across the seven scan suites on PHP 7.4 and 8.4, 33 more in the
browser client, and 269 in the database matrix on MySQL 5.7/8.0 and MariaDB
10.5/10.11 under both isolation levels. The whole repository suite is green on
both PHP versions and on Node.

## 1.8.24 - a finished manifest is not a finished scan

Catch-up, the summary, and the one place a run is allowed to say it finished.

**The reconciler.** Planning freezes a list of record ids so ordinals mean
something and progress is a cursor. The moment it is frozen it starts going out
of date, and a run that finished the frozen list and called that complete would
certify a project it provably had not seen - which is C3 in the review, and the
review's point was that FULL runs acquire that defect the instant the list is
frozen, not only incremental ones.

So between a finished manifest and a finished scan sits a fixed window,
(opening fence, target fence], walked to its end. The window is captured once,
and that is what makes the phase terminate: a window that moved every round
would be a phase chasing a project people are still using.

A changed record is one of four things and they are not the same. Created
during the run: added, and the total moves with it, because completeness
measured against a number known to be wrong is not a measurement. Created but
out of scope: not added, because a DAG-scoped run that widened itself here would
put another group's records in front of a reader who may not see them. Deleted:
tombstoned, never requeued - a deleted record can never be read, so requeueing
it holds the run open forever, which is C3's mirror case. Edited after we read
it: requeued. Edited before we read it: nothing, and that branch is what makes
the confirming round cheap.

When the change log no longer reaches back to the opening fence there is no
window and no honest way to enumerate what moved. That is not a failure - the
records were examined - but it is not a fence either, so the run keeps
`manifest-complete` and says it cannot prove the project stood still.

**The summary** is built once at the end from bounded keyset pages, not by
`GROUP BY` at read time: the summary is the first thing rendered, so a report
that recomputed it per page would put its slowest query in front of every
reader. The counters ADD, which is why each page writes its counts and its
cursor in one transaction - a crash between them inflates the summary against
the findings it describes, and nothing downstream could detect that. Superseded
finding versions are excluded, or the same problem is counted twice.

**Promotion** is now one file, and it is the file this whole rebuild is for. The
legacy scan assigned `complete` at the bottom of a loop a `continue` could skip,
so a run that examined nothing produced the same string as one that examined
everything. `ScanPromotion::facts()` is a pure function from run state to the
inputs of `ScanOutcome::derive()`, which is what makes the hard decisions -
whether a tombstone blocks, whether a pending finalizer means "not yet" or
"nothing to do" - testable as a table rather than buried in a transaction.
Nothing promotes over an unfinished manifest, an unread or unstable record, an
undecidable duplicate group, an unfinished finalizer, a changed fingerprint or
policy. A cancellation or an unrecoverable failure ends the run whatever is
outstanding, because waiting for a finalizer on a run nobody wants is how a
cancelled scan keeps its project slot.

**Three defects the tests found, all of them disagreements between the two
stores.** The in-memory store dropped the source version a record was scanned
at, which would have requeued every record the change log mentions on every
run. `SqlScanStore::run()` never selected `fence_target`, so promotion could not
see a fence it had just written and would have downgraded every fenced run to
manifest-complete. And the two stores returned different SHAPES from
`aggregates()` - named keys from one, numeric from the other - with every caller
written against whichever it was developed on. All three are now in the shared
contract, which is the point of running one assertion set twice.

**And the NULL-in-a-unique-index trap, for the second time in two releases.**
`uv_scan_aggregate` keyed on `(run_id, kind, axis1, axis2)` with nullable axes.
MySQL counts every NULL in a unique index as distinct, so `ON DUPLICATE KEY
UPDATE` never fired, every page got a row of its own, and the summary would have
reported one page as the whole. The axes are NOT NULL with an empty default now,
which is also the honest representation: "no Data Access Group" is an answer.
The in-memory store keyed by string concatenation and merged them happily; four
real servers did not.

365 fast checks and 269 in the database matrix, on both engines under both
isolation levels. Still nothing user-visible: the scan page renders its
unavailable notice, and no entrypoint reaches any of this.

## 1.8.23 - deciding duplicates without holding the project

Uniqueness is the only check this module makes that is a property of the whole
project rather than of one record - no record is a duplicate on its own
evidence - so it is the only one that cannot be finished while scanning. It now
has its own phase, and `UniqueFinalizer` runs it.

**The group is a keyed hash, never the value.** A `@UVUNIQUE` rule can sit on a
Notes field, and a Notes field is up to 64 KB; a value per candidate would be a
second copy of the project sitting in a table more people can read than can read
the project. Candidates carry a project-scoped HMAC and a location, and the
values are re-read from the source only for the groups that turn out to hold
more than one record.

**And the hash is verified rather than trusted.** Two different values sharing a
SHA-256 HMAC is not something data entry can produce, and it is checked anyway,
because the alternative to checking is asserting - and the assertion is "these
two participants have the same hospital number", about people. When a group's
values disagree, the group is marked undecidable and NO verdict is emitted for
it. Partitioning the group by value is the tempting response and would turn a
hash failure into a confident wrong report. Values that could not be re-read at
all block the group the same way, for the same reason.

**Bounded, including the pathological case.** A rule on a field where every
record holds the same value puts every record in one group. Verification and
emission both page by keyset and persist their cursor, so nothing accumulates a
group. The database matrix finalizes a 20,000-candidate group and compares its
peak memory against a group 400 times smaller: they are within a few megabytes
of each other, which is the property - the page size sets the footprint, not the
group.

**Staged, then published.** Duplicate findings are written with no active slot,
so no report can see them, and the group's published epoch is what makes them
visible. Half a duplicate group is a report naming one of two matching records
and not the other, which is worse than naming neither. A record edited during
finalization moves the group's candidate epoch, abandons its staged rows and
starts the group again; the abandoned rows are swept in bounded pages.

**A unique key the active-identity one could not supply.** Staged rows are keyed
by (generation, identity, staging epoch). The active-identity key cannot do this
job: a staged row has no active slot, MySQL counts every NULL in a unique index
as distinct, and a retried emission page would therefore insert a second copy of
every row it had already written. The test that re-emits a published group
exists to hold that.

**The worker will not walk past a finalizer nobody configured.** Advancing would
turn a wiring mistake into a report containing no duplicate findings at all,
which reads exactly like a project that has none. It stops and says so.

Two changes to the finding table, and one note about why they are still version
1: nothing in the module calls `migrate()` yet, so no installation has this
schema, and adding a version 2 would ship an upgrade path nobody could take
while hiding the real shape behind it. The moment a release enables the scan,
every change becomes a new version.

Emission writes one statement per page rather than one per finding, which took
the 20,000-candidate group from minutes to seconds. 314 fast checks and 241 in
the database matrix, on both engines under both isolation levels.

## 1.8.22 - the worker: prove it held still, look, and commit or commit nothing

`ScanWorker` is the durable scan's engine, and `WorkBudget` is what stops it
exceeding a request.

**The stable read is four steps rather than one.** Read each record's source
version, read the records, read the versions again, keep only what did not
move. A scan of 100,000 records reads a project people are still using; without
this, a record edited during the read is examined half in its old state and half
in its new one, and the finding describes a state the project was never in.
Requeueing costs one re-read. Certifying costs the report its meaning. A record
that will not hold still after the configured attempts becomes a reported
blocking exclusion rather than being retried forever or quietly left out, and a
record deleted mid-run reaches a tombstone so the manifest can still complete -
otherwise the run waits forever while holding the project's scan slot.

**Every failure has a state, and none of them is silent.** A read that fails
commits nothing and leaves the rows claimable, because a failed read judged as
an empty one is the mistake this module exists to prevent. An evaluator that
throws costs one record, not the batch, and that record is reported unexamined
rather than clean. A cancel arriving mid-evaluation is discovered at the final
compare-and-set, and everything buffered is discarded - proved here against a
real server with the cancel issued from a second connection.

**Two things end a run rather than pausing it.** If the validation rules changed
underneath it, there is no partial claim that would be true, so it finishes
terminally and releases the slot for the run that must replace it. If the
project's privacy settings were tightened, it stops before writing anything
further - tightening takes effect at once by design, and the run stores the
policy it began under.

**`WorkBudget` is predictive, not reactive.** Noticing that memory is nearly
exhausted is too late: the allocation that finishes the job is the one that
kills it, and an OOM is the single failure mode a module built on "nothing
fails silently" cannot narrate, because the process that would narrate it is
gone. So each batch is sized from what the last one actually cost, growth is
capped at double so one unrepresentative batch cannot produce a fatal next one,
and a batch is refused unless its predicted peak leaves 40% of the limit free.
A record too large to examine beside others is examined alone - never zero,
because excluding it without trying records a guess as a fact.

Both ini values have a setting that means "no limit" and reads as a very small
number if taken literally: `memory_limit = -1` and `max_execution_time = 0`. A
memory limit of minus one byte refuses every batch and a time budget of zero
seconds stops the scan before it starts, and neither failure announces itself -
the scan simply never progresses. Both are tested.

**The store needed two more things.** `advancePhase()` walks a run along the
chain with the transition table deciding rather than the caller, fenced on the
lease epoch. And progress now counts only records that became TERMINAL: a
requeued record is written back as pending, which changes its attempt count and
its timestamp, so counting affected rows walked the figure past the manifest
total.

308 checks in the fast worker suite and 220 in the database matrix. Two
mutations were run to confirm the tests bite: removing the stable-read guard
fails three checks, and removing the commit fence fails ten. The scan still does
not run - the entrypoints and the feature flag are next.

## 1.8.21 - planning: from a project to a frozen manifest

`ScanPlanner::plan()` is the step that turns a project into a run: it names
every rule, fingerprints the configuration, captures the opening fence, streams
the record list into the manifest a page at a time, and freezes it.

**Everything that can refuse does so before a run exists.** A refused start
costs a message; an abandoned run costs the project its scan slot until
something expires it. So an installation that cannot list records, a project
with no rules, and a group-scoped request on an installation that cannot tell
which group a record is in are all turned away before `startRun()`. Once a run
does exist, every remaining failure FINISHES it terminally rather than leaving
it - a planner that dies quietly is indistinguishable from one still working.
That is asserted directly: after a planning run exhausts its time budget, the
project has no active run and the next attempt is not told it is busy.

**The opening fence is captured before the manifest, not after.** Captured
after, a change during the walk falls outside the window the run believes it
covers - which is the single gap a fence exists to close.

**A group-scoped run gets a group-scoped manifest.** Building the whole project
and filtering at display time is the leak the persisted store creates: the
stored report outlives the request that produced it and is readable by whoever
can open the page. The manifest is the right place for the scope, and the run
records how many records it deliberately left out.

Nothing accumulates during the walk: a page is read, hashed, written and
dropped. That is the whole difference from the legacy path, which built the
entire record set in PHP before examining any of it.

Planning is tested against the real record source and the real store on all four
database services rather than against a fake - a planner judged by a fake source
proves only that the fake agrees with it. 205 checks in the database matrix, 247
in the fast worker suite. The scan still does not run.

## 1.8.20 - naming a rule, walking a project, and fencing a read

Three pieces of planning, and the store changes they forced.

**`ScanPlanner` names rules so a stored finding still points at one.** The
legacy scan cited a rule by its position in `getRules()`, which concatenates the
settings rules and then the annotation rules in dictionary field order - so
adding one settings row, or moving one field in the Online Designer, renumbered
every annotation rule after it. Harmless while findings lived for one request;
the moment they persist, a stored "rule 7" starts pointing at a different rule
and nothing in the data can detect it. A settings rule now uses a stored id when
it has one and a content-derived name otherwise; an annotation rule is named by
where it is written. Revision is kept separate from identity, so editing a rule
changes what the report says about it rather than making it a different rule.

**The fingerprint refuses to be built from the wrong things.** Missing an input
throws, because a fingerprint that omits an input fails to notice the change it
exists to notice and fails quietly. Message wording is refused outright: if a
typo invalidated the fingerprint, fixing one would force every project to
re-scan 100,000 records, so typos would not get fixed. And the canonical
encoder is not `json_encode` - the L-01 finding recorded that values carry
invalid UTF-8 from Latin-1 imports, `json_encode` returns false on those, and
its substitute flag collapses distinct invalid bytes to one replacement
character. That is a data-constructible collision, which is what L-01 was.

**`RecordManifestSource` walks records without exporting them.** REDCap's record
index first, the project's data table second, both probed rather than inferred
from a version number, and every column read out of `information_schema` rather
than guessed. If neither can enumerate records in bounded memory it refuses
before a run exists - the alternative is the whole-project export that killed a
128 MB installation around 2,500 records.

The page boundary is the interesting part. Record columns are usually collated
case- and accent-insensitively, so `WHERE record > 'abc'` can step over a record
called 'ABC', and a skipped record is a record certified without being read.
Ordering on the binary form fixes that and throws away the index, turning the
walk quadratic. So the walk pages with `>=` and carries the ids already emitted
at the boundary - and asks the SOURCE TABLE which ids the server considers
equal, rather than comparing two bound parameters. That distinction is not
theoretical: this schema's columns are `utf8mb4_unicode_ci`, which pads, while
MySQL 8.0's default connection collation does not, so the parameter form answers
differently from the column on the same server. It would have passed on MariaDB.

**`SourceFence` proves a record did not move.** `log_event_id`, never a
timestamp - a second is long enough for several saves and a clock can step
backwards. The event taxonomy is deliberately ignored: any log row carrying a
record counts as a change to it, because being over-inclusive costs a re-read
and being under-inclusive presents a changed record as covered. Fences are
compared as decimal numbers rather than as ints, floats or strings, all three of
which get a big enough log id wrong. A pruned log refuses to certify its
interval instead of reporting that nothing changed in a window it cannot see.

**What the store needed.** A million-record manifest cannot arrive as one PHP
array, so planning now appends pages and freezes at the end, and the total is
COUNTED from the rows rather than accumulated while writing them. Appending is
idempotent because the record walk deliberately re-offers its page boundary -
which the in-memory store handled in PHP while four real servers rejected it on
the first run of the matrix. It is `ON DUPLICATE KEY UPDATE`, not `INSERT
IGNORE`: both make a re-offer harmless and only one leaves a real write error an
error.

Two follow-on defects, both found by running rather than by reading. Appending
in pages leaves gaps in the ordinals, and `claim()` advanced its cursor by a
COUNT - so it stepped over live rows and stranded them below the cursor forever;
it now takes the next N pending rows and moves the cursor to the last one taken.
And a record requeued after a failed stable read sits below the cursor where
`claim()` can never offer it again, so `claimPending()` claims by state instead,
reclaiming rows a dead worker left behind. Progress now counts what actually
became terminal rather than what was offered, so a re-offered record cannot push
the figure past the manifest total.

244 checks in `tests/scan_worker_php.php`, 58 in the shared store contract, and
186 in the database matrix across MySQL 8.0 and MariaDB 10.11 under both
isolation levels. Still nothing runs a scan.

## 1.8.19 - Task 6 begins: the phase a run is in, and the phases it may reach

`ScanPhase` is the durable scan's state machine. Seven phases, and a transition
table that decides which writes are allowed rather than leaving it to whichever
of five callers - the planner, the browser worker, the cron worker, both
finalizers and the canceller - happens to be writing.

**The chain is strictly forward, one step at a time:** planning, scanning,
catch-up, unique-finalize, rollup-finalize. No skipping, and that is the design
rather than a restriction. A run with no unique rules still passes through
`unique-finalize` and records that it had nothing to do, because promotion
requires both finalizers to have completed and "completed" must not be
satisfiable by never having started. Allowing the skip would make "nothing to
do" and "never ran" the same stored fact, and only one of those may certify a
project. There is no backward step either: catch-up requeues manifest rows and
processes them itself, so a stuck run is always stuck somewhere identifiable.

**Two escapes.** Any active phase may go to `cancelling`, and any phase may go
to `terminal` - a store failure has to be recordable from wherever it happened.
`cancelling` exists so the epoch bump and the terminal write are separate
events, which is what lets a worker mid-evaluation discover it has lost the run
before anything it buffered can reach the tables. A cancelling run only
finishes; it never returns to work. A finished run is never reopened, because a
retried finaliser would otherwise overwrite a terminal state a report has
already been exported against.

**The nullable terminal is a correctness rule, not tidiness.** `terminal IS
NULL` is how every read path asks whether a run is still going, so a row
carrying a terminal state while still working would read as finished to a query
that never looked at the phase. `consistent()` refuses both halves of that
mistake, on write and on read - the read direction because a row written by a
different build must not be interpreted by whichever column the reading code
consulted first.

**How it is checked.** `tests/scan_worker_php.php` asserts all 49 (from, to)
pairs against a matrix written out by hand, so a change to `ScanPhase` that its
author believed was equivalent has to be made twice before it passes - the same
differential technique that holds the PHP and JavaScript engines together. Every
refusal must also explain itself; a refusal nobody can debug is a refusal that
gets removed. The file also transcribes the rebuild plan's terminal-derivation
table row for row, checking all five columns at once, so a change that satisfies
one prose assertion elsewhere while breaking a row of the specification still
fails. 186 checks, green on PHP 7.4, 8.3 and 8.4.

Nothing here runs a scan. The page still renders its unavailable notice; the
feature turns on in 1.9.0, behind a flag, after the worker exists.

## 1.8.18 - Task 5 complete: slots, retention, and a fault that is real

`WorkerSlots` and `ScanRetention` are now their own classes rather than methods
on the store, and both gained the parts that were missing rather than just
moving.

**`WorkerSlots`** is the installation-wide semaphore. Provisioning is additive
only: raising the limit adds rows, lowering it deletes none, because a row being
deleted may be leased right now and its worker would carry on holding nothing.
`idleAbove()` reports which slots could safely go, and leaves the decision to an
operator. A browser worker and a cron worker compete for the same pool - that is
the point of rationing the server rather than the project - and an abandoned
lease returns to the pool on its own, which is the difference between a semaphore
and a leak.

**The CHANGED-versus-matched trap, a third time.** `renew()` wrote an expiry and
asked `affected()`. A worker renewing twice in the same second with the same TTL
writes the value it already had, changes nothing, and would be told it had lost
its lease - so it would stop working while still holding a slot, leaking capacity
and stalling the scan. Zero is genuinely ambiguous here, so `renew()` now asks
rather than assumes: if the row is still ours at the same epoch, the no-op
succeeded. A takeover in the gap answers false, which is the safe direction - a
worker that stops unnecessarily costs one batch, and one that continues after
losing its slot costs the limit the slot exists to enforce.

**`ScanRetention`** keeps three clocks apart on purpose. Value previews expire
soonest, because the value is the only participant data here; runs expire later,
because a finished run is evidence of what was concluded; and abandoned runs
expire much sooner still, because they hold a project's scan slot - that is a
deadlock break wearing retention's clothes. An abandoned run becomes terminally
`expired` with `partial` coverage, never `complete`. Purge cascades children
before parents, because there are no foreign keys and that ORDER is the cascade.
`revokePreviews()` bumps the policy revision first so previews stop being
readable in the same request that tightened the policy, rather than whenever a
cron next runs.

**Fault injection, not simulated failure.** A finding whose `reason_code` exceeds
its column makes the server refuse the write, and the batch rolls back entirely -
no partial findings, the record still `PENDING` so the work is re-claimable, and
`manifest_done` unmoved. A half-written batch would mark records done whose
findings were never stored, which is the one outcome that produces a confidently
clean report over unexamined data. A write refused under `LOCK TABLES` returns
rather than escaping as a fatal, because a worker needs to stop and a fatal would
leave the run with no terminal state at all.

One test bug worth recording: a local `$n` in the retention block silently
replaced `check()`'s global counter with a result set, and the suite died
incrementing an array several checks later, nowhere near the cause.

`tests/mysql/run.php` 95 -> 127 checks, green on MySQL 8.0 and MariaDB 10.11
locally under both isolation levels. Suite green on PHP 7.4, 8.3 and 8.4.

## 1.8.17 - one contract, two implementations

`tests/scan_store_contract.php` holds 35 assertions about what a `ScanStore`
must do. `ArrayScanStore` runs them in the fast suite in milliseconds;
`SqlScanStore` runs the *same* 35 against MySQL 5.7/8.0 and MariaDB 10.5/10.11,
under default isolation and READ COMMITTED. Both pass.

`ArrayScanStore` is not a mock of the SQL store - it is an independent
implementation of the same contract. That distinction is the whole value. A mock
returns what the test told it to and proves only that the test agrees with
itself; two implementations judged by one assertion set disagree wherever the
contract is ambiguous, and ambiguity is where the bugs are. It is the technique
this repository already uses to keep the PHP and JavaScript rule engines from
drifting, applied to storage.

The contract pins the behaviour that is easy to get subtly wrong: busy names no
owner and carries no digit; a run id does not resolve under another project; a
claim at a stale epoch returns nothing rather than a range it could not commit;
an overtaken worker commits nothing *and* leaves its records re-claimable; a
retried finaliser cannot reopen a finished run; a stale holder and an impostor
both release no slot.

**What the fast suite explicitly does NOT prove**, stated in both files so it
cannot be misread: the concurrency invariants. Single-process PHP has no second
connection, so "the engine refuses a second active run" is `ArrayScanStore`
checking an array - a description of the intended behaviour, not evidence of it.
The evidence is in the database matrix, and passing here is not a substitute.

`tests/mysql/run.php` 60 -> 95 checks. New fast suite `tests/scan_store_php.php`
at 35. Suite green on PHP 7.4, 8.3 and 8.4.

## 1.8.16 - SqlScanStore, and the fence that only a real server disproved

Docker was available, and both portable PHP builds ship `php_mysqli.dll`, so the
database matrix now runs locally in seconds against MySQL 8.0 and MariaDB 10.11
containers before anything is pushed. That changed the economics immediately:
the previous three defects each cost a CI round trip to find, and the one below
was found and fixed twice over in the time one round trip takes.

**`SqlScanStore`** implements the storage contract over `ScanDb`, a four-method
adapter (`select`, `exec`, `affected`, transactions). The indirection is not
taste: the framework's `query()` exposes no affected-row count, and every fenced
update in this design is decided by exactly that number. It also means the
database matrix exercises the *same* `SqlScanStore` REDCap will run, over a plain
mysqli connection, rather than a second implementation that agrees with it.

**The fence bug.** `commitBatch()` fenced itself with an UPDATE that set
`updated_at` and required `affected() === 1`. MySQL reports rows **CHANGED**, not
rows matched - so when the commit landed in the same second as the manifest
write, the timestamp did not change, the statement reported zero, and a perfectly
good batch rolled itself back. Intermittent by construction: it depended on
whether the clock had ticked.

MariaDB on default isolation passed while MySQL failed, so a single-engine test
would have shipped a scan that silently discarded work under load. The fence is
now `SELECT ... FOR UPDATE` with the epoch compared in PHP, which is what the old
comment claimed and the old code did not do - the transaction really does hold
the run row now, so a concurrent cancel serialises behind it instead of racing
it. The counter update afterwards is deliberately *not* gated on `affected()`
either: a batch that finished zero records changes no column and would have
rolled itself back for having nothing to say.

The class docblock now states the rule that generalises: **if success does not
change a value, `affected()` cannot tell you whether it happened.** Single
-statement mutations that necessarily change a column - claim, cancel, finish,
lease, release - are still decided by the count; multi-statement transactions
fence with a locking read.

**What the store is now proved to do**, on MySQL 8.0 and MariaDB 10.11, under
default isolation and READ COMMITTED: a second start returns busy without naming
the run, its owner or its scope; a run id does not resolve across projects; the
manifest publishes its total with its rows; a claim at a stale epoch returns
nothing; an overtaken worker commits nothing and leaves its records re-claimable;
a retried finaliser cannot reopen a finished run; slots hand out exactly the
configured number and a stale holder releases nothing; and an expired value is
cleared while its finding remains, because a report that shrinks as it ages reads
as the project having improved.

`tests/mysql/run.php` 27 -> 60 checks. Full suite green on PHP 7.4, 8.3 and 8.4.

## 1.8.15 - MySQL and MariaDB do not agree on what the variable is called

**Both MySQL legs are green.** 5.7.44 and 8.0.46, under the server default and
under READ COMMITTED, 25 and 27 checks with no failures. That is the first
evidence any of this actually works: the schema installs on the oldest InnoDB
default without truncating a binary key, `UNIQUE(project_id, active_slot)` really
does permit unlimited NULLs so one active run per project is enforced by the
engine, worker-slot limits of 1, 2 and 5 hand out exactly that many leases, an
expired lease can be taken over and a live one cannot, a worker whose epoch moved
changes nothing, a cancellation beats an in-flight worker to its compare-and-set,
and closing a finding version lets the next generation insert while history is
retained. All of it holds under READ COMMITTED as well as the default.

**MariaDB failed on the verification query, not on the invariants.** The
`SET SESSION TRANSACTION ISOLATION LEVEL` succeeded - the run printed
`isolation: READ COMMITTED` - and then died reading `@@transaction_isolation`,
which MariaDB 10.5 and 10.11 do not have. The variable has two names and neither
server in this matrix has both: MySQL 8.0 removed `@@tx_isolation`, and MariaDB
has not yet added `@@transaction_isolation`. It now tries each and treats
"neither answered" as a failure rather than as a pass, because the alternative
lands straight back on a check that reports success without checking anything.

That is the third defect in a row found by running against real servers, and the
third that a mock would have certified. The first two claimed a schema was broken
when it was not and claimed an isolation level that was never selected; this one
was a portability assumption baked into the instrument.

## 1.8.14 - a test that named a condition it never created

Round two of the database matrix. The `information_schema` fix from 1.8.13 held -
health passed on every server - and two further defects surfaced, both in the
harness rather than in the schema.

**The type string was hand-counted, twice, wrongly.** `mysqli::bind_param` wants
one type character per variable, and getting it wrong is a fatal rather than a
failed check: it does not fail one assertion, it silently un-runs every assertion
after it. I wrote nine characters for ten variables, then corrected to eleven,
and each attempt cost a full CI round trip to discover because the fatal killed
the process before anything downstream could report. There is now one `bindAll()`
helper that derives the type string from the value list, and zero hand-written
type strings remain in the file.

**The READ COMMITTED step never selected READ COMMITTED.** The workflow ran the
suite twice and set `UV_DB_ISOLATION` on the second pass; nothing in `run.php`
read it. So the step re-ran the server default and reported a pass for a level it
had never selected - a green tick claiming coverage that did not exist, which is
worse than an absent test. The level is now applied from an allowlist (it cannot
be a bound parameter, so it must be one of a known set) and then **verified**
against `@@transaction_isolation` on both connections, because a `SET` that
silently did nothing would put the job straight back to claiming a level it never
chose.

Nothing in `php/` changed in this release. Both defects were in the instrument,
and both were the kind that reports success.

## 1.8.13 - what the database matrix found on its first run

`.github/workflows/scan-database.yml` ran for the first time and failed on MySQL
5.7 and 8.0 alike. Both failures were mine, and one of them would have disabled
the durable scan on every installation.

**`SHOW TABLES LIKE ?` is not preparable.** `Schema::health()` asked whether each
of its tables existed using a bound parameter on a `SHOW` statement. `SHOW` is not
supported in the client prepared-statement protocol, so the statement failed
instead of matching; health() caught its own exception and reported a complete,
correctly migrated schema as broken. The direction was safe - it refuses rather
than certifies - but the answer was wrong, and a health check that always says
"broken" is a feature that never turns on.

It now asks `information_schema.tables` with a bound `table_name`. That form is
preparable everywhere, scopes to the current database explicitly rather than
implicitly, and treats the underscores in the module's own table names as literal
characters rather than as single-character LIKE wildcards.

`ScanCapabilities::tableExists()` had the same latent bug at a different call
site and is fixed with it.

**The mock could not have caught this.** `tests/scan_schema_php.php` modelled
`SHOW TABLES LIKE ?` faithfully - and a mock that answers a query no server
accepts is a mock that certifies a query no server accepts. That is the same
defect class as the chunk mocks in 1.6.3 and the string-versus-array label mocks
in 1.8.5, and it is precisely why the plan puts these invariants behind a real
database rather than behind more mocking. The fake now models the query the code
actually issues.

**A harness bug hid the rest.** `bind_param` in `tests/mysql/run.php` carried nine
type characters for ten variables, so the run died with an `ArgumentCountError`
before the finding-version checks executed. The job's only real signal was the
health failure above it; everything after was unreported rather than passing. A
health failure now also prints which tables it thought were missing, because a
bare pass/fail on a schema check is unactionable.

Suite green on PHP 7.4, 8.3 and 8.4.

## 1.8.12 - Task 5: the settings the policy resolver reads

Seven system settings and four project settings, matching the plan's table. The
split is the point: a project may always ask for LESS than the server allows and
never more, so every retention and budget limit exists twice - a system maximum
an administrator sets once, and a project request that can only tighten it.
Concurrency and retry limits are system-only, because they ration the server
rather than the project.

The four project settings are declared BEFORE the repeatable rule list rather
than after it. External Modules renders settings in declaration order, and
burying a retention control under a list that grows to three hundred entries is
how a privacy control goes unread.

`config.json` is edited textually rather than re-serialised: a round trip through
a JSON encoder reformats all 199 lines and buries a 58-line addition in a diff
nobody can review.

**A drift check, because this failure is silent.** `ScanPolicy` reading a key
that `config.json` does not declare returns the default forever - the setting
appears in no interface, changes nothing, and nothing fails. Twelve checks now
assert that every key the resolver reads is declared, and that each system
maximum is declared as a SYSTEM setting: declared per project, a project could
raise its own ceiling, which is the one thing they exist to prevent.

`tests/scan_security_php.php` 121 -> 140 checks. Suite green on PHP 7.4, 8.3 and
8.4, and on Node.

## 1.8.11 - Task 5: the permission matrix, the outcome table, and the keys

Four classes that are load-bearing for everything in Tasks 6 and 7, and all four
are PURE - they take rights arrays, run facts, bytes and settings and return
answers. That is design, not luck: a security decision that needs a database to
test is a security decision that will be tested against a mock of a database,
which is how this module previously shipped a control that passed every test and
did nothing in production.

**`ScanAuthorization`** implements the plan's permission matrix. Starting a run
needs design rights, readable access to EVERY instrument in the run's entitlement
set, and **full identified-data export rights** - not merely some export rights,
because the run STORES values and what is stored outlives the level the reader
had when they asked. One inaccessible instrument refuses the whole report rather
than narrowing it: filtering rows still leaks through the count, the rollup, the
filter options, the cursor, the timing and the filename. A form with no entry in
the rights row is barred, because a row that says nothing about an instrument is
not a row that grants it.

Every denial is non-disclosing. A DAG user asking about another group's run gets
the same words as one asking about a run that does not exist - a distinct message
would be an existence oracle. A busy project returns no run id, no owner, no
scope and no digit at all. Before a DAG run reaches its target fence, status
returns phase, heartbeat and the control flags and nothing that describes data,
with an explicit `detail_withheld` flag so absence is not read as zero.

**`ScanOutcome::derive()`** is the terminal-state table as one function, with
four dimensions kept deliberately separate: terminal, coverage, detail and clean.
A run can be complete and not clean; it can have zero violations and not be
clean; it can be fenced and truncated. Collapsing any pair produces a sentence
that is true of the run and false of the project. Failure outranks cancellation,
which outranks expiry, and a blocked record caps coverage before the fence is
even consulted - a proved fence over a manifest with a hole in it still has a
hole in it. Export suffixes compose, because a reader who learns only one of
`_MANIFEST_ONLY` and `_TRUNCATED` draws the wrong conclusion from the other.
Label degradation stays non-blocking, or the tick is unreachable on any install
with a metadata gap. Collection gaps never block clean and never go unmentioned:
the obligation travels with the outcome as `mustShowGaps`.

**`Hmac`** separates four hash spaces - record identity, finding identity, value
fingerprint, uniqueness group - by purpose and by project. With one key and no
purpose label those spaces coincide, and a value fingerprint equal to a record
hash tells an observer that a field contains a record id. A missing key throws
rather than falling back to an unkeyed hash, because an unkeyed hash of a record
id is a lookup table for anyone holding the report. Finding identity is
location plus rule plus reason and deliberately NOT the value, so a wrong value
that changed to another wrong value stays the same finding instead of looking
like churn on every re-scan.

**`ScanPolicy`** resolves effective limits as min(system maximum, project
request) - a project may always ask for less and never more - and every parse
failure lands on the documented default rather than on "unlimited". Collection
gaps are fixed at `separate` with no off switch, because a project that turned
them back into violations would re-create the 95%-noise report the rebuild exists
to remove. `tightened()` marks any reduction in disclosure or retention, which is
what makes a privacy downgrade take effect immediately instead of at the next run.

`ScanStore` is the storage contract with its six invariants written down where
they belong - on the contract, not inside one implementation that a future store
could quietly drop.

`tests/scan_security_php.php`: 121 checks. Full suite green on PHP 7.4, 8.3 and
8.4. The scan remains withdrawn; none of this is reachable by a user yet.

## 1.8.10 - Task 5, first slice: the schema, and where its invariants are proved

The durable foundation, installed INERT. Tables exist and a health check answers;
no worker runs and nothing reads them. That ordering is the plan's, and it is
deliberate: a persistence bug and a batching bug are indistinguishable if they
arrive in the same release.

**`php/Scan/Schema.php`** owns versioned, idempotent DDL and the administrator
diagnostic. Every statement is `CREATE TABLE IF NOT EXISTS`, so a retry after a
partial install resumes rather than conflicts, and there is no `DROP`, `DELETE`,
`TRUNCATE` or `ALTER` anywhere in a migration - a migration that can delete is
one that can delete the wrong thing during a retry.

Three properties are enforced in code rather than in prose:

- **A strict allowlist.** `table()` is the only function that produces a
  qualified identifier and refuses anything not declared, so a typo fails at the
  call site instead of interpolating a wrong - or attacker-influenced - name into
  DDL. The prefix is a constant, not a setting: one that varies at runtime is one
  that can be pointed at REDCap's own tables.
- **"Not installed" and "could not ask" are different answers.** A missing
  version table is a fresh install; any other read failure returns null and
  attempts nothing. Installing over a schema whose state is unknown is how a
  half-migration gets migrated again from the beginning.
- **A failed statement fails the migration.** It stops where it stands, does not
  continue to the next table, and never records a version for work it did not
  finish. The diagnostic points at `Schema::plan()` so an administrator whose
  database user holds no `CREATE` grant can install the DDL by hand.

Two structural invariants are the storage engine's job, not PHP's: at most one
active run per project, and one active version per finding identity. Both use a
nullable `active_slot` in a UNIQUE key, because MySQL permits unlimited NULLs
there - so history is retained while "at most one active" is unviolatable. A
read-then-write check in PHP is a race; a UNIQUE key is not.

### Where those invariants are actually proved

Not here. `tests/scan_schema_php.php` (44 checks) proves what is decidable
without a database: the allowlist, idempotency, the fresh-install-versus-failed
-read distinction, that a failed statement records nothing, and that `health()`
never calls a half-installed schema usable.

It cannot prove the concurrency invariants, and asserting them against a mock
would be the exact failure this module has shipped before - v1.4.0 disabled
`@UVUNIQUE` in production while every mocked test passed. So `tests/mysql/run.php`
opens **two independent connections** and asserts what the second one observes:
a second active run refused by the engine, a terminal transition freeing the slot
while the finished run stays on record, worker-slot limits of 1, 2 and 5 handing
out exactly that many leases, an expired lease taken over and a live one not, a
worker whose epoch moved changing nothing, and a cancellation beating an
in-flight worker to its final compare-and-set.

`.github/workflows/scan-database.yml` runs it against MySQL 5.7 and 8.0 and
MariaDB 10.5 and 10.11, under the server default isolation and again under READ
COMMITTED, and fails the job if any module table is left behind. The guarantees
differ across those servers; a green tick on one is not a green tick on the fleet.

### Not yet

This is the first slice of Task 5. Still to come in it: `ScanStore`/`SqlScanStore`
with the transaction boundaries, `ScanAuthorization` against the plan's permission
matrix, worker slots, retention and value expiry, HMAC purpose separation, and
the fault-injection tests. Tasks 6 and 7 follow. Nothing in this release changes
what any user sees - the scan remains withdrawn.

## 1.8.9 - the project-wide scan is withdrawn

**The scan page no longer runs a scan, and the CSV route no longer produces a
file.** Both now say so. Live as-you-type validation, the save-time audit and the
uniqueness check are untouched and continue to run; nothing about day-to-day data
entry changes.

### Why

An independent release review rejected 1.8.7 and blocked the release. Its central
finding is correct and is not a bug that can be patched: the work since 1.6.2
hardened the LEGACY synchronous scan and rebuilt its report, but never built the
architecture `reports/scan-rebuild-plan-2026-08-17.md` describes. Tasks 5 to 9 -
the durable run store, the resumable worker, leases, source fences, quotas,
retention, cancellation, keyset paging and stored-result exports - do not exist.
What shipped was a better-behaved version of a design whose cost grows with the
project rather than with the work.

That is measurable rather than theoretical. A live pid-135 run produced 1,902
findings from 39 records - roughly 49 per record. At that density a 100,000-record
project produces about 4.9 million findings, and the screen's finding array alone
would want something on the order of two gigabytes before anything is rendered.
Both entry points also started that work from a GET, with no lock: a refresh, a
second tab or a retried download each launched another independent full pass.
That is an availability problem before it is a performance one.

Task 1 of the plan requires exactly this disable, with an explicit notice, until
the worker exists. It was previously recorded as an accepted deviation and kept
live. That decision is reversed.

### What this release does

- `pages/scan.php` renders the notice and nothing else. The Run and Download
  controls are removed rather than left inert: an offered control that refuses is
  an invitation to file a bug. Rights are still checked first, and still refuse,
  because who may see the page is not contingent on what it currently offers.
- Both `run=1` and `csv=1` are read from `$_GET` **and** `$_POST`. The controls
  were GET-only, so a GET-only check would have let a POST fall through to a page
  that looks like it simply found nothing. A request that asked for a scan is told
  its request did nothing; one that merely opened the page is not.
- `pages/export.php` refuses before rights are considered - there is no file for
  anyone to be entitled to - and answers **503**, not 403: this is "not available
  yet", not "not permitted", and a monitor retrying on 503 is behaving correctly.
  Its previous body is deleted rather than left unreachable. Task 7 specifies a
  different exporter, streaming from the stored run with expected-count metadata
  and a mandatory `export_complete=1` trailer; keeping the old writer would invite
  it to be re-enabled instead of replaced. It is in the history at 1.8.8.
- `scanProject()` itself is untouched and still fully exercised. Withdrawing the
  ENTRY POINT is the change; keeping the engine intact is what lets the durable
  worker reuse its verdicts rather than reimplement them.

### The clean predicate moved

It lived as three local variables inside the page, so the only way to test it was
to render HTML and grep it - and it would have lost its coverage along with the
page. `ScanPageView::verdict()` now answers on three axes that are routinely
confused: STATUS is whether the sweep finished, COVERAGE is what finishing is
worth on this installation, and CLEAN is the only one that is a claim about the
project. A run that finished, found nothing, and could not prove the project
stayed still is complete and not clean.

The same move applies to the report layer. `ScanColumns`, `ScanDimensions` and
`MessageCatalog` are live code the durable report will consume, so their tests
now assert on those classes directly instead of on page markup. That is stricter
than what it replaced: a column's presence is checked against the descriptor list
rather than inferred from a `<th>` appearing in a string.

### Deliberately NOT done

Three things asked for during this cycle are not here, because adding them would
decorate an architecture that is being replaced:

- **Collection gaps.** Never-started instruments produced 929 of those 1,902
  findings. The plan makes untouched-form policy part of the run fingerprint
  (§6 step 6), so it belongs in the planner, not in the report layer.
- **REDCap username, mobile-app username and last-change columns.** These need
  batched per-page enrichment against the project's log shard, bounded to the
  keys on one page. Against a whole-project synchronous scan that is a query per
  finding.
- **Filtering.** The plan specifies server-validated filters with signed keyset
  cursors over stored findings. A filter over a truncated in-memory array would
  narrow a view that is already silently incomplete, which is worse than no
  filter.

### On version numbers

The plan assigns 1.9.0 to Task 6, 1.10.0 to Task 7 and 1.11.0 to Task 8. This
line stays in 1.8.x until the durable path lands, so those numbers keep meaning
what the plan says they mean.

### Verification

`tests/scan_page_php.php` 121 -> 128 checks; fifteen of the new ones fail against
the previous pages. Full suite green on PHP 7.4, 8.3 and 8.4, and on Node.

## 1.8.8 - an empty event map is an answer, not a failure

Found on the first live run of 1.8.7, on pid 135 (DARE-TB), a real classic
project.

1.8.6 made the Event column survive an unreadable event map, on the grounds that
dropping it is the claim "every finding here is in the same event" and an
unreadable map cannot support that claim. The reasoning holds; the
implementation read an EMPTY answer as an unreadable one. REDCap returns no
event names for a classic project because there are none to return, so every
classic project grew an Event column carrying one repeated internal event id on
every row, under a yellow warning that labels could not be read. Nothing had
failed. That is noise on the majority of projects, and it was shipped by a
change whose whole purpose was to stop a report saying something untrue.

`getEventNames()` and `getInstrumentEventMappings()` return nothing in BOTH
cases, so neither can tell them apart. REDCap's own project object carries the
flag, and it is now asked first - guarded on the global existing, being an
object, and being about THIS project, because a `$Proj` left over from another
pid would answer confidently about the wrong one.

Only an explicit "not longitudinal" clears the column and the warning. A null
answer - no project object, an older build - leaves 1.8.6's behaviour standing,
because "cannot tell" must not drop a column that may be the only thing
separating two rows. Older builds that expose `numEvents` but not the flag are
read through the count.

`tests/scan_page_php.php` 111 -> 121 checks, with the mock gaining a stand-in for
the project object; three of the new checks fail on the pre-fix tree. Full suite
green on PHP 7.4 and 8.3 and on Node.

### What the same live run confirmed

Reported here because a fix nobody watched work is a fix nobody has evidence
for. On pid 135, 39 records against 322 rules: all fifteen report columns
present; the table capped at 1,000 rows with the count line still speaking for
all 1,902; 929 required-blank rows carrying an EMPTY value cell and none
claiming `[withheld by policy]`, against 71 real values that correctly carry it
(1.8.6); authored rule messages reaching the "What is wrong" column with
"Wording from" naming the tier. No rule was barred by the 1.8.7 instrument-rights
gate for a full-rights user, and no instrument was wrongly reported as
designated to no event - the two changes most likely to be inert or over-eager
against a real REDCap rights row and a real event mapping.

## 1.8.7 — instrument rights, and a cache that could not recover

A fifth pass (`reports/scan-wargame-round4-2026-08-18.md`) attacked surfaces the
earlier rounds never touched, and deliberately re-tested none of their findings.
Eight of its thirteen probes confirmed something. Four are authorization, one is
a cache whose failure mode is a scan that cannot recover inside a request, and
three are gaps between what the code does and what the docs say it does.

The report also records a first pass of its own that reported the authorization
finding clean, because the value came back `NULL` from the project default rather
than from any rights check. A probe that stops at the first `NULL` certifies a
control that is not there.

**Design rights are not instrument rights.** The scan reads through
`REDCap::getData()` with a project id and no user, so REDCap's own per-instrument
access control never runs on it. A designer with **No Access** to an instrument
received that instrument's findings, and on a project that had opted into raw
values, its values. The export-rights ceiling added in 1.8.x caps how much of a
value is shown and says nothing about which instruments a reader may see at all —
the docblock that introduced it names this exact case.

Rules that read an instrument the reader cannot open are now dropped **before**
evaluation and reported as rule problems naming the instrument. Before, not
after: filtering rows afterwards still moves the finding count and still puts the
instrument's label in a summary, so a rule that never runs is the only version
with nothing left to leak. Two questions with different answers:

- A rule's **condition** is rule-wide. One `when` or `assert` operand on a barred
  instrument decides every host's verdict, so the rule is skipped everywhere.
- A rule's **hosts** are independent. Annotation rules pool by configuration, so
  one rule routinely spans several instruments; barring it outright would discard
  the hosts the reader is entitled to. The barred host goes, the rest are checked.

Rights that cannot be read clear nothing, the same posture `mustRedact()` takes.
An instrument with no entry in the rights row is barred rather than assumed open:
a row that says nothing about a form is not a row that grants it. The scoping is
something a caller asks for, because `scanProject()` is also reachable with no
user to scope to; both pages ask for it, which `tests/scan_page_php.php` asserts.

`userFormRights()` also learned to read **through** a pid-keyed rights array, as
`scanScope()` already did for `group_id`. Without it the gate barred every rule on
any build whose rights come back nested — found by the mock that exists for that
shape.

**The export needs export rights.** `data_export_tool = 0` is REDCap for "No
Access to the data export tool", and the file was still served with only its
values downgraded — so a user barred from REDCap's own exporter could pull a
project-wide findings file from one URL. The download is refused and the button is
replaced by the reason. The screen is unaffected: reading a report inside REDCap
is not the same act as walking out with the file, which is the distinction the
export right exists to draw.

**One failed dictionary read no longer disables the request.** The cache was a
single slot, tested before `$pid` was read, and it stored the failure as eagerly
as the answer. One transient failure dropped every annotation rule, every
field-name check and every host resolution for the rest of the request, and no
later call could recover it because no later call asked again. The docblock
promises that an explicitly passed `$pid` is preferred — precisely because
`getProjectId()` is unreliable in import, API and cron contexts — yet after the
first call the argument was never read again, so one call without a project
context poisoned every later call that passed the right pid. Now keyed by pid,
holding successes only.

Fixing it was not enough, and the probe written for it said so: `getRules()`
memoised the empty rule list that an unreadable dictionary produces, so the
second scan still saw no rules. Annotation rules are read out of the dictionary
and setting rules are validated against it, so a list built without one is not
"no rules" — it is "we could not tell". It is memoised only when the dictionary
was readable.

**A chunk read is bounded by cells, not by records.** Every rule field, every
`when`/`assert` operand and every composite unique partner goes into one
`getData()` call, so a project with 1,500 ruled fields built a 1,500-column
export of 200 records at once — and the 1.6.4 halt guard measures memory
*between* chunks, so it notices after the allocation that caused the problem.
Wide projects now read fewer records per pass and say so in the limits; 200
records of 200 fields, which is what an ordinary project already cost, is
unchanged. The run's own limits are merged with the installation's rather than
overwritten by them, which had been discarding the only kind a reader can act on.

**The value ceiling defaults to `locations`.** `scanPlan()` fell back to `raw` —
the most disclosing option — as the default of the one expression whose job is to
cap disclosure, twenty lines from a `valueRank()` docblock stating that anything
unrecognised ranks lowest. Both pages pass a ceiling, so it was latent. This is
the third site in that class; the other two were fixed in 1.8.6.

**Documented:** under `dag` scope, records in no group form one group of their
own and are compared against each other. That is the only consistent reading, and
neither `README.md` nor `config.json` said so.

### Confirmed clean

Recorded so the next reviewer does not re-spend the time. Record ids that PHP
coerces as array keys (`'007'`, `'0'`, `'1e3'`, `' 8'`) survive the manifest, the
chunk read and the report intact. Six malformed record-node shapes all produce a
reported result rather than an exception. Markup in a field label, a rule note and
a rule message renders escaped. A config-error rule between two live rules does
not shift either one's labels. The `MessageCatalog` memo added in 1.8.6 could not
be made to serve one finding's sentence to another.

### Verification

`hosting_php` 162 → 178 checks, `scan_page_php` 107 → 111. Fourteen of the new
checks fail on the pre-fix tree. Full suite green on PHP 7.4 and 8.3 and on Node:
17 PHP files and 17 JS files, 0 failures.

## 1.8.6 — the round-three findings

A fourth adversarial pass (`reports/scan-wargame-round3-2026-08-18.md`) re-ran
the 1.8.1–1.8.5 fixes and asserted the property each was supposed to establish
rather than the absence of the symptom it had shown. That method mattered: the
report records five of its own earlier probes returning the wrong verdict, one
of them certifying a fix that had never happened. Every check below was run
against the pre-fix tree before it was kept — six fail in `hosting_php`, seven in
`scan_page_php`.

**A record whose group cannot be read no longer joins a group-scoped scan.** The
DAG filter tested `$dagFilter !== null && is_array($node) && dagOf($node) !==
$dagFilter` as one conjunction, so a record REDCap did not return as an array
failed the test and was *admitted*. The record then reached a report whose header
states it covers one group only, and its id was printed there through the
unread-record note. A group that cannot be established is not this group: the
record is excluded, the count of exclusions is stated, and no id is named. The
scan says `incomplete`, because records it could not classify are records it did
not cover. `tests/hosting_php.php` gained a `badnode` read shape for this — the
existing `nonarray` shape fails the whole read, so the branch had no test that
could reach it.

**A required-blank no longer claims a value was withheld.** In locations mode the
report shows a marker where a value exists and was withheld. The test for that
was whether the finding carried a `value` key — and the required path sets
`'value' => ''` unconditionally, so the key always exists and every
required-blank rendered `[withheld by policy]`. That is an affirmative false
statement, made on the one finding type whose entire content is that the field is
empty, and it is worse than the empty cell it replaced. The test is now whether
there is a value.

**`reportValue()` no longer defaults to showing everything.** A missing
`valueMode` fell back to `raw`, twenty lines below a docblock promising that
anything unrecognised is treated as the least disclosing option. `scanPlan()`
always sets the key, so nothing reached it — a default that is safe only because
nobody takes it is not a safe default. It falls back to `locations`.

**A rule on an instrument that is on no event now says so.** `hostContextsFor()`
drops every context for an instrument designated to no event, so the rule
produced no violation and, because nothing reached the evaluator, no rule problem
either: the scan certified a project containing a rule that had enforced nothing
since the day it was written. It is reported per host instrument, so a rule
spanning two forms is still checked on the mapped one. The check fails open — an
unavailable or empty instrument-event mapping is what a classic project returns,
and reading that as "no instrument is collected" would declare every rule dead.

**`?csv=1` no longer scans the project twice.** 1.8.5 replaced the second
exporter with a redirect, and landed it below the `$run || $csv` condition that
runs the scan. The deprecated route therefore scanned the whole project,
discarded the result unread, and redirected to a page that scanned it again.
No assertion about output could see it, because the output was identical; the
page mock now counts reads, and the route performs none.

**The screen and the file scrub the same bytes.** 1.8.5 removed NUL, SUB and ESC
in `csv()` only, so one stored value was sanitised on its way into the download
and passed through raw into the HTML table. Both now call one `scrub()`. Two
things surfaced while doing it: `ScanPageView` held a raw SUB byte inside the
comment explaining why SUB is removed, and writing the pattern with real control
bytes rather than `\x` escapes is the PHP 7.4 "Null byte in regex" fatal that the
7.4 matrix leg caught during 1.8.5.

**The Event column survives an unreadable event map.** Dropping the column is the
claim "every finding here is in the same event", and an unreadable map cannot
support it. All three routes to the longitudinal flag failed together, the column
was dropped anyway, and two findings in different events rendered as identical
rows. The column is now shown whenever the project cannot be proved
single-event; `event()` already fell back to the raw id.

**One explanation resolution per row.** The "What is wrong" and "Wording from"
columns are two views of one resolution and each walked the catalog chain
separately. The memo is on the template, never on the finished sentence: the
catalog is a data file, and a cache over the sentence would hand this row the
previous row's value the day an entry uses `{value}`. An authored message is
still returned verbatim, braces included.

### Still open, and why

The wargame's standing register lists four items this release does not close.
Three need a decision about scope rather than a patch, and are recorded here so
they are not mistaken for oversights.

- **Never-started instruments emit one violation per record.** Aggregating them
  into a collection-gap dimension changes `scanProject()`'s result contract and
  both exporters. It belongs with the untouched-form gate in the rebuild plan.
- **Design rights alone read forms the user cannot access.** `scanScope()` reads
  `hasDesignRights` and `group_id`; `getData()` carries no `userid`. Adding one
  changes what every scan sees, which is a deliberate change, not a patch.
- **The record list is still materialised before any budget check**, which is the
  rebuild plan's own subject.
- **`@UVALIDATE` rejects `message`.** Annotation-configured check-character rules
  are now the only rules with no wording channel.

### Verification

`hosting_php` 146 → 162 checks, `scan_page_php` 96 → 107. Full suite green on PHP
7.4 and 8.3 and on Node: 17 PHP files and 17 JS files, 0 failures.

## 1.8.5 — the rest of the battle-test list

**Control bytes no longer reach a cell.** Formula defusing already looked past
leading whitespace and a BOM, but nothing removed NUL, SUB or ESC from a value:
a NUL truncates the cell in several readers, `SUB` is end-of-file to some
importers, and ESC begins a terminal escape sequence for anyone who `cat`s the
file — so one value read differently depending on what opened it. TAB, CR and LF
are kept, because they are legitimate inside a quoted field.

**A sink that throws no longer escapes the scan.** The sink is a caller-supplied
consumer — a spool, a socket, a table. When it threw, the exception took the
whole result with it: no status, no incomplete list, nothing recording that the
project had not been examined, which is the one failure the contract exists to
prevent. The record is now named as unreportable and the sweep continues.

**One exporter, reached two ways.** The legacy `?csv=1` route emitted a
different schema from `pages/export.php` — unquoted columns, no value, no
explanation, no BOM, no `_INCOMPLETE` suffix — so two live formats answered the
same question differently and a consumer had to know which URL made its file. It
now redirects. The buffer teardown stays, because a `Location:` header is a
header like any other and would otherwise be ignored exactly as the CSV headers
were.

**Findings and labels come from one rule read.** A report resolved "Rule 12"
through a second, independent `getRules()` call and joined the two by array
position, so a rule added or reordered between them moved every label onto the
wrong finding, silently. The scan now carries the rule list its ordinals refer
to, and both pages resolve against that.

**A hidden choice is no longer filed as a wrong value.** The code was a legal
option when it was saved and the rule list changed under it — a design change
with existing data behind it, not a data-entry error, and it goes to a different
person.

**Unreadable label sources are stated on the page.** `degraded[]` recorded from
the start why a column had fallen back to raw ids, and nothing ever displayed
it, so an event id shown in place of a name was indistinguishable from data.

**The record list is sliced, not `array_chunk`ed** — that built a second copy of
every id up front and held it for the whole scan.

### The test-quality defect underneath several of these

`getEventNames()` and `getGroupNames()` answer two ways: called with an id they
return one name as a string, called without they return the whole map as an
array. The page mocks only ever returned the string, so `ScanDimensions` — which
needs the array — always saw nothing. `events` was therefore always empty and
`hasDags` always false, and the two assertions that a classic project shows no
Event column and a group-less project no DAG column passed **because the labels
were unreadable, not because of project shape**. Neither column had ever been
rendered by a test. That is the same defect class 1.6.3 fixed in the chunk
mocks, in a new place.

The mocks answer both shapes now, and the SHAPE section renders both columns for
the first time: a longitudinal project showing a named event, a classic one
omitting the column by shape, a project with groups showing the group, one
without omitting it, and an instrument label preferred over its machine name.

> One of my own fixes was PHP-8-only. The control-byte pattern was written into
> the file as REAL control bytes rather than escape sequences; PCRE2 tolerated
> it and 7.4 refused a NUL in a pattern, taking 18 checks with it. Caught by the
> 7.4 leg of the matrix, which is the entire reason it runs.

## 1.8.4 — a scan may only claim what the server can prove

The plan's central safety property, unmet since it was written: *"`complete`
means every in-scope record was stably validated at or beyond a recorded
source-change fence… If a reliable source fence cannot be proven, the strongest
terminal coverage state is `manifest-complete`; it must never render as complete
or clean."* `ScanCapabilities::policy()` computed exactly that cap, and **nothing
called it** — a correct, tested implementation of the module's own contract that
production never consulted, which is worse than not having written it, because
the suite reported the property as covered.

**The fence is now proved, not inferred.** `sourceFence()` returned available
the moment a table NAME matched a pattern — it never asked whether the table had
the columns a fence needs, whether this project had any rows, or whether the
ordering column was usable. `policy()` turns that answer into
`complete-through-fence` and `incremental = true`, so a name that merely looked
right licensed both. One bounded query answers it properly; a project with no
log history, a non-numeric ordering column, an unqueryable log or a throwing
probe are each reported as what they are.

**COVERAGE is now a separate axis from STATUS.** Status says whether the sweep
finished. Coverage says what finishing is worth on this installation. A run that
read every record on its opening list, on a server that cannot prove the project
did not move underneath it, is `manifest-complete` — and the green tick, the
green count and the unsuffixed filename are all withheld from it, with the
reason stated rather than the verdict simply missing.

Two ordering mistakes of my own, both found by probing rather than by reading:
the coverage verdict consulted `$result['status']` one line before status was
assigned, so every run came back `partial` and the tick was withheld from scans
that had earned it; and the early `nothingToScan` return carried no coverage at
all.

The page mock gains a `query()` that can answer the fence probe, so the tick is
reachable in a test and its absence means something. Without that, "no tick"
would pass for the wrong reason — the same trap the battle-test found in the
event and DAG column checks, where the mocks returned strings where arrays were
required and neither column had ever been rendered.

`tests/scan_capabilities_php.php` gains C-08: a proved fence, an empty log, a
non-monotonic ordering column, a silent module and a throwing probe, plus the
consequence each has for `maxCompletion` and incremental mode. The old fixture
reported a fence on a module whose every query returned nothing.

## 1.8.3 — the battle-test findings

An adversarial battle-test (`reports/scan-wargame-2026-08-17.md`) ran probes
rather than arguments and found six criticals the first review missed, plus
reproductions of what it had argued. A seventh is mine, found while fixing them.

**A clean project's export crashed.** The dimensions were built lazily inside
the sink callback, which never fires when there are no findings, so `$dims`
stayed null and the metadata block fatalled on `keyLegend(null)`. Downloading a
clean report returned a 500. This was introduced in 1.8.2 and no test caught it
because every export test uses data with a violation. Built eagerly now.

**A halted scan reported the manifest as the headline.** `stats['records']` was
set before the chunk loop and never revised, so a scan that stopped at the first
boundary printed "Scanned 400 record(s)" in bold and `| records 400` in the
export, with the truth in a bullet inside a warning box. The count is now what
was examined; the manifest size keeps its own name. The whole point of 1.6.4 is
that a stopped scan says so, and it was saying the opposite in larger type.

**Hashed-record-id mode leaked the raw ids.** `log-values = none` exists for
sites where the record id is itself identifying. Findings were hashed; the
`incomplete` notes naming the same records were not — and those are rendered on
the page and written into the CSV twice, for exactly the records a site is
chasing.

**A project-scope `@UVUNIQUE` was silently wrong under a DAG scope.** The scan
reads one group, so a duplicate in another group is invisible and the rule
reports nothing — with status `complete` and no note anywhere. The live
unique-check endpoint queries the whole project and would flag it, so the two
disagreed and the scan was both wrong and the one issuing certificates. It is
now reported as a rule that could not be evaluated.

**The dialog's Rule label and Message were discarded for check-character and
pooled rules** — the two kinds the module is named after. Both were read inside
the `constraint | required | unique` branch only. So the Rule name column added
in 1.8.0 was permanently blank for them, `MessageCatalog`'s first tier (the
author's own wording) was unreachable for them, and `docs/TESTING.md` told a
tester to verify a label that could never appear on the most common rule kind.

**Losing the HMAC key emptied the Record column.** `reportRecordId()` guarded
with a `catch`, but `hashedIdentifier()` returns null rather than throwing, so
the documented `[record id withheld]` fallback could never fire. Every finding
rendered with a blank Record cell — on screen, a table of violations with no way
to reach any of them.

**The export certified projects that enforce nothing.** `pages/scan.php` builds
its clean predicate from status, findings and rule problems; the export used
status alone. A project where every rule is a configuration error exported as
"No violations found." with no marker in the filename. One predicate now, and a
`_NOT-CLEAN` suffix for the case where the scan completed but the rules did not.

## 1.8.2 — the fifteen medium findings

The rest of the implementation review, grouped by what was actually wrong.

**Things the report silently dropped.** The reason code and the rule's kind were
folded away entirely: `issue` maps five rule types onto three words, and the
reason reached the reader only through the message catalog, whose first tier is
the author's own message and therefore wins for every finding of that rule. A
single-value rule carrying both a pattern and a check-character algorithm
produced identical rows for a format failure and a mistyped check digit. `check`
and `reason` are columns again.

**Withheld no longer looks like blank.** `reportValue()` returned null for
"policy says never", for "this finding has no value", and for an empty string
alike, so a locations-only report rendered an empty Value cell — exactly what a
genuinely blank required field renders. The column now shows
`[withheld by policy]`.

**Degradation nobody could see.** `ScanDimensions::degraded[]` was written in
four places and read in none, which made its own docblock untrue; the export
prints it. Two of those writers were also wrong. Column visibility came from
whether the label READ succeeded rather than from the project's shape, so an
unavailable `getEventNames` deleted the Event column from a longitudinal project
and two findings on the same field in different events rendered identically. And
a non-array `getGroupNames` return recorded nothing, so a failed DAG read was
indistinguishable from a project with no groups.

**CSV integrity.** Formula defusing inspected byte zero only, so a leading
space, tab, carriage return or BOM carried a payload straight to the spreadsheet
parser. The header row emitted labels rather than stable keys, so any wording
improvement would have broken every downstream consumer; it now emits keys, with
a `# columns:` line mapping them for a human. A clean scan produced a file with
no header row at all, and the trailer rows were shorter than the finding rows —
the header is unconditional now and the trailers are padded, so the file is
rectangular.

**A fatal path in the export.** The sink callback built the label snapshot
lazily on the first finding, which put `getRules()` and `Branching::resolve()`
on a path called from inside `scanRecord()`, where a throw escapes
`scanProject()` past both `try/catch` blocks and fatals with nothing recorded
about the project not being examined. It is built up front, the callback catches
its own failures and reports them in the file, and the page ends in `exit` so
nothing the router emits afterwards lands inside the CSV.

**Two independent reads of the rule list.** A finding cites a rule by ordinal,
and the report resolved that ordinal against a second `getRules()` call that was
not memoized. Anything changing the rule list between the two shifted every
ordinal, attaching every label and message to the wrong rule undetectably.
`getRules()` is now memoized per request — on success only, because a memoized
throw would be the H-05 mistake. Stable rule identity remains Tasks 5–6.

**Probes that proved nothing.** `schemaPrivilege` substring-matched `CREATE`, so
`CREATE TEMPORARY TABLES`, `CREATE VIEW` and `CREATE ROUTINE` all passed, as did
any database or user name containing the word; it parses the privilege list now
and accepts only a plain `CREATE`. `recordEnumeration` returned available when
two prerequisites existed without ever issuing a query — a gate that passes on
prerequisites is not a gate — and probes both sources for real. `sourceFence`
claimed a fence as soon as a log table name resolved, checking neither retention
nor the event taxonomy, which would have handed `complete-through-fence` to
nearly every installation the moment `policy()` is wired up; it refuses and says
what has to be verified first.

**Unique candidates.** Each held the raw value alongside a key that already
contained its hex form — roughly three times the bytes, project-wide, held to
the end, and even in locations mode where it can never be shown. The reportable
form is decided at collection time instead.

**The README overclaimed.** It said the download holds one row at a time. The
row buffer does; the scan behind it still accumulates the record list, the
unique candidates and one note per unreadable record, and the export spools the
whole report to disk before sending a byte. That is now stated as unfinished
work rather than implied to be solved.

## 1.8.1 — values are a decision, not a default

An implementation review against the rebuild plan (`reports/scan-implementation-review-2026-08-17.md`)
found seven blocking items in the 1.8.0 work. Five are fixed here; two are
recorded deviations.

**The value default is inverted.** 1.8.0 defaulted `scan-value-storage` to `raw`.
An External Modules dropdown stores nothing until its settings dialog is saved,
so `getProjectSetting` returns null on every project nobody has reconfigured —
which is all of them, on upgrade. That default would have switched every
existing installation from locations-only to full raw disclosure with nobody
deciding anything. The default is now `locations`, an unreadable or
unrecognised setting resolves to `locations`, and the modes carry the plan's
names: `locations` / `identifier-redacted` / `raw`.

**A reader's export rights now cap what the project chose.** Design rights are
independent of form-level access and of export rights in REDCap, and the scan
reads through `\REDCap::getData()` with a project id and no user, which bypasses
per-user rights entirely. A design-rights user with **No Access** on an
instrument and **De-Identified** export rights could therefore download raw
values for every field of every record. `data_export_tool` now sets a ceiling —
full data set for `raw`, de-identified or remove-identifiers capped at
redaction, anything else at locations-only — and the ceiling can only lower the
project's setting, never raise it.

**Zero records in scope is no longer certified clean.** `if (!$ids)` reported
`complete`, so a DAG filter that resolved but matched nothing rendered the green
tick over "Scanned 0 record(s)". That is S-03, the defect 1.6.2 exists to fix,
by a different route: 1.6.2 refused when the DAG *name* could not be resolved,
not when it resolved and matched nothing. The three causes — an empty group, an
export that did not carry groups, a name that disagrees with the exported label
— are indistinguishable from inside the scan, so the run reports incomplete and
says so.

**The export ran a different scan from the screen.** `pages/export.php` called
`set_time_limit(0)`, which sets `max_execution_time` to 0, which makes
`scanProject`'s deadline null and silently disables the halt guard added in
1.6.4. The screen could stop for time and report `incomplete` while the export
of "the same" scan ran on and reported `complete`. The call is gone.

**Three places still promised the report never shows a value** — the scan page's
docblock, the paragraph the operator actually reads above the table, and
`scanProject`'s own docblock. The README was corrected in 1.8.0; these were not.

### Recorded deviations from the plan

> **Task 1's disabling of the legacy scan is deliberately not done.** The plan
> says to refuse `run=1` and `csv=1` and show a notice until the durable path
> exists. The durable path is blocked on measurements that need a live server,
> and disabling the only working feature in the meantime leaves operators with
> nothing. The GET-triggered scan and both export routes stay live, reviewed and
> chosen rather than overlooked. `pages/export.php` is a third entry point added
> in 1.8.0 and is in the same position.
>
> **`ScanCapabilities::policy()` is still not consulted in production.** The
> module contains a correct, tested implementation of the completion cap and
> does not call it, so `complete` is still claimed with no source fence. That is
> the plan's central safety property and it remains unmet; it is listed here so
> the test suite's green does not read as coverage of it.

## 1.7.0 — findings can leave the scan as they are found

`scanProject()` appended every violation to one array and returned it whole.
That array is the scan's dominant cost — ~440 bytes a row, and the live project
measured in 1.6.4 produces ~49 rows per record — so it grows with the project
and nothing ever flushes it. Past a certain size the scan dies of memory
exhaustion, which is an uncatchable fatal: the process stops before the return,
the page renders nothing, and nothing records that the project was not examined.
1.6.4 put a guard in front of that wall. This release moves the wall.

The scan is now three pieces:

- **`scanPlan()`** — which rules are live, where each lives, what has to be
  read. The half that does not depend on the data, so a caller working through a
  project in slices computes it once rather than per slice. Its failures are
  fatal by nature, so they come back as one reason rather than mixed in with
  per-record notes.
- **`scanRecord()`** — every live rule against one record, unchanged. The
  whole-project state it needs, the unique candidates and the deduped rule
  problems, is threaded by reference; both are bounded by the RULE list rather
  than by the data.
- **`FindingSink`** — where violations go as they are found. `ArrayFindingSink`
  collects them exactly as before and is the default. `CallbackFindingSink`
  hands each row straight on and keeps none. `CountingFindingSink` keeps only
  the count, which is the cheapest honest answer to "how many would this project
  produce".

**`scanProject()` keeps its signature and its return shape.** Every existing
caller and all 26 call sites are untouched; the sink is a fourth, optional
argument. `stats` gains a `violations` count for everyone, so a streaming caller
can still say how many findings there were — "no violations" must never be
inferred from an array that was simply never filled (M-02).

Only violations stream. Rule problems are bounded by the rule list and
`incomplete` notes by the number of unreadable records, so both stay on the
returned result where every caller and test already reads them.

`tests/hosting_php.php` gains the SINK section: **107 checks total**, and the
existing 70 pass unchanged — which is exactly the trap, because passing proves
the ARRAY path is unchanged and says nothing about the streaming one a large
project will actually use. So four scenarios — ordinary findings, the
whole-project duplicate tail, a record that cannot be examined, and a project
with nothing live — each run through both sinks and every field of the result is
compared. That is the same differential shape already used to stop the PHP and
JS condition engines drifting apart.

> `uniqueGroupKey()` was listed for this release and is **not** extracted. Its
> purpose is to let an in-memory and a durable path share one key construction,
> and there is no durable path yet; pulling it out now would be a second caller
> that does not exist. It goes in when persistence does.

## 1.6.4 — the scan stops on its own terms

Measured on a live REDCap 17.0.6 project rather than estimated: 39 records, 329
rules, 1,914 violations, **~20 seconds warm** — and the scan reported itself
incomplete on both runs, because one record with a hyphenated id was requested
and not returned. Twenty seconds for thirty-nine records is roughly 0.5s per
record. A project of four thousand does not finish inside a default execution
limit, and the module had no idea: a repo-wide grep for `set_time_limit`,
`max_execution_time`, `memory_get_usage` or `memory_limit` returned nothing.

Both limits kill PHP with an **uncatchable fatal**. The process stops before
`scanProject()` can return, the page renders nothing, and nothing anywhere
records that the project was not examined — no status, no `incomplete` entry,
just a blank screen indistinguishable from a dropped connection. That is the one
failure the whole status contract cannot express (M-03), and it is now the
*expected* exit on a real project rather than a pathological one.

The chunk loop takes a budget: 75% of `max_execution_time`, 70% of
`memory_limit`. Crossing either stops the sweep, records how many records were
not checked, and leaves the status `incomplete`. It is checked **between chunks
and nowhere else** — stopping part-way through a record would leave it
half-examined with nothing written down, which is the silent skip the guard
exists to prevent (H-05). A limit that cannot be read imposes no cap at all,
because a guard that fires on a misparse would stop healthy scans and report
them as incomplete.

A short run also under-reports **duplicates** specifically, since uniqueness is
the one check that needs the whole project. That is a wrong negative rather than
a missing row, so it gets its own sentence in the report instead of hiding under
the general warning.

- The record-list pre-read no longer falls back to exporting **every rule field
  for every record in one unchunked call** when `getRecordIdField()` is
  unavailable. That put the whole project in memory before a single record had
  been examined and defeated the chunk loop entirely. REDCap's first
  data-dictionary field *is* the record identifier, so it is derived from there;
  only if that also fails does the scan refuse, and say why.
- `$idData` is released once the id list is built, and each chunk's rows before
  the next read allocates. Previously both were held to the return.

> The Tier 1 hoists planned for this release — memoizing `Logic::parse` and the
> check-character pattern analysis — were **dropped on the measurement**. They
> were costed in single-digit seconds at four thousand records against a budget
> we now know is exceeded roughly sixtyfold. 39 records x 329 rules x ~1 context
> is ~12,800 rule evaluations in 20 seconds, or 1.5ms each; nothing in a regex
> or a date comparison costs that, so the time is structural and the hoists
> cannot reach it. Where it actually goes is being measured before any more of
> it is optimised.

`tests/hosting_php.php` gains the H-08 and H-09 sections: 70 checks total, 17 of
which fail on 1.6.3. The halt decision and the byte-size parser are driven
directly rather than through the ini, because making PHP enforce a real
execution limit inside a test kills the test process, and `ini_set` refuses any
`memory_limit` below current usage — a test that went through the ini would
quietly assert against whatever the limit already was.

## 1.6.3 — the chunking tests could not fail

No behaviour change. This closes a hole in the test suite that would have hidden the next release's
work, which is why it lands before that work and not after it.

`scanProject()` reads records in chunks: `array_chunk($ids, $chunkSize)` and then one
`REDCap::getData(['records' => $chunk, ...])` per chunk. Both test mocks ignored
`$params['records']` and returned the whole project on every call — `tests/hook_php.php` returned
`self::$data` outright, and `tests/hosting_php.php` filtered on `fields` only. So the one existing
chunking test, `scanProject(149, null, 1)`, proved that the loop ITERATED and nothing whatsoever
about which records each iteration asked for. A scan that requested the wrong slice every time, or
the same slice three times, still saw every record and produced identical findings.

Both mocks now honour `records`. The record list is read without that key, so the pre-read is
unaffected; only chunk reads narrow.

Nothing in the module was wrong — the suite stayed green. That is worth stating precisely, because
"we changed the mock and nothing broke" is not evidence that the mock now matters. A mutation
answers it: with the chunk read rewritten to request `array_slice($ids, 0, 1)` instead of `$chunk`,

- against the OLD mocks, **1** check failed, and it was one of the new ones added here;
- against the fixed mocks, **7** failed, six of them assertions that already existed — H-03's
  cross-instrument duplicate, H-04's once-per-record rule, and both L-01 sanity controls.

Those six were written to defend correctness properties that a wrong-slice batch read violates, and
until now none of them could see it.

`tests/hook_php.php` gains five checks that read what the chunk reads actually asked for — one
chunk read per record, one record per chunk, every record covered exactly once, one unfiltered
pre-read — plus the differential property that chunk sizes 1 and 500 produce identical findings and
status. That last one is the invariant a batched scan will have to keep, so it is pinned now while
it is still cheap to check. 283 checks total.

## 1.6.2 — the scan page gets a test, and stops certifying scans it never ran

Found by reviewing the validation scan for a project expecting 100,000 records. `pages/scan.php`
turned out to be the only PHP in the module that CI never linted, the package never asserted, and no
test ever executed — and it could not *be* tested: it declared `uv_h()` and `uv_csv()` as
namespace-level functions, so a second include in one process was a fatal redeclare.

Four defects, three of which the page could hide because nothing was watching it:

- **An unresolvable Data Access Group certified a scan of zero records.** A DAG-bound user whose
  group name could not be resolved got an `'__unresolvable__'` sentinel. It matched no record, so
  the scan read nothing, reported `'complete'`, and rendered the green **✓ No violations found** —
  a clean bill of health for a project it never examined. The downloaded CSV was worse: with the
  status `'complete'` it carried no `# INCOMPLETE SCAN` banner at all. The conservative intent was
  right; the outcome inverted it. There is no scope, so there is nothing to certify: the page now
  refuses. This is M-02 in a place M-02 had not been looked for.
- **The rights probes failed open.** DAG confinement was gated on
  `method_exists($user, 'getRights')`. The framework serves methods through `__call()`, for which
  `method_exists()` answers false — the same probe that silently disabled `@UVUNIQUE` in production
  in v1.4.0 while every mocked test passed. A false answer here skipped the DAG block entirely, left
  the filter null, and let a DAG-bound user scan and display every other group's records.

  Whether that fires on a given REDCap build depends on whether `ExternalModules\User` declares
  those methods or proxies them, which cannot be determined from this repository — the framework
  source is not vendored, and every `TestUser` stub in `tests/` declares them for real, so no test
  here can tell the two apart. That is exactly the blind spot the v1.4.0 postmortem describes.
  Treated as a live fail-open on that basis rather than argued down.

  Every `method_exists` probe on a user object is now `is_callable`, not just the two on the scan
  page: `redcap_module_link_check_display` (fails closed, hides the menu link) and the two
  entitlement probes behind cross-form `@UVASSERT` (fail closed into the
  `\REDCap::getUserRights()` fallback) carried the same defect at lower severity. Separately,
  `getRights()` returns a
  **project-keyed** array on some builds, where `$rights['group_id']` is simply unset and reads as
  "not confined" — the same leak by a different route. The page now reads through that shape, and
  refuses outright when scope cannot be established rather than assuming there is none.
- **The CSV was not a CSV.** `config.json` sets `"show-header-and-footer": true`, so REDCap emits
  its entire page before this file runs and the `header()` calls were ignored. A downloaded report
  was ~3,000 lines of HTML with the data starting at line 1,089 — unusable in Excel, and the reason
  this release exists. The buffered chrome is now discarded before the headers, and the rows are
  written straight to `php://output` instead of being accumulated as an array of lines and then
  imploded into one string: the report used to exist three times over in memory before a byte was
  sent.
- **The repeat-metadata cache answered a question it was never asked** (H-07, and H-02 for the sixth
  time). `repeatingFormsForEvent()` has two sources: a whole-event map, and a per-form probe used
  when the build does not expose the map. The per-form answer describes only the forms it was
  handed, but it was cached under a form-independent key — and in the scan, `hostContextsFor()` asks
  about a single host form *before* `contextResolution()` asks about the whole read set. The
  one-form answer was therefore served to the all-forms caller, and every form it had not asked
  about read as non-repeating. An instrument that repeats but has no instances yet then resolved as
  a plain blank, and the scan reported a **hard-blocking violation against a value it had never
  read**. The two sources now have two caches. The deliberate null fall-through is kept: a source
  that could not answer must not harden into a permanent verdict (H-06).

The rendered violation table is also capped at 1,000 rows — it was one `<tr>` per violation, which
is ~25 MB of markup on a 4,000-record project. The count beside it is **not** capped, so a truncated
view can never read as a smaller problem than the scan found, and the CSV still carries every row.

`uv_h()`/`uv_csv()` are now `ScanPageView::h()`/`ScanPageView::csv()` in `php/ScanPageView.php`.
`pages/scan.php` and `php/ScanPageView.php` join the CI lint list and the package assertion.

Two smaller things the CSV rewrite needed. If an output buffer cannot be deleted — one opened with
a callback, or without the erasable flag — the rows would simply be appended to the chrome still
sitting in it, reproducing the same hybrid silently; that case now refuses alongside the
already-flushed one. And the per-form probe cache key joins form names with `\x1F` rather than a
comma, for the same reason the unique group key avoids printable delimiters (L-01).

`tests/scan_page_php.php` is new — the page's first coverage: 54 checks, 13 of which fail on 1.6.1
with the helper refactor applied but the rights and DAG fixes reverted, including both cases that
print another group's record ids. It asserts the CSV headers fire with *nothing* already buffered,
which is the actual defect rather than its symptom, and it drives the CSV in a subprocess because
that path ends in `exit`. `tests/hosting_php.php` gains the H-07 section: 50 checks total, 3 of
which fail on 1.6.1 — one naming the mechanism and two the harm.

## 1.6.1 — a blank field is not a failed read

Found on a live REDCap 17.0.6 project while testing the 1.6.0 deployment, not by a test.

A same-form rule — both operands on the page being rendered, `blockSave:"hard"` — shipped as
`["const", false]` with `deferred: true` and the reason *"reading its saved value failed"*.
Nothing had failed. Both fields were simply **blank**. Entering `5` against a minimum of `10`
produced no verdict, no outline, and saved cleanly. Saving once so the fields held values made the
same page validate and block correctly, which is what identified the trigger.

REDCap omits a blank field from `getData` output, so a record whose every REQUESTED field is blank
comes back with **no node at all** — and `readValues()` read that absence as a failed read. This is
the same principle the per-field default already rests on ("absence is not, by itself, unresolvable"),
applied one level up: it was right for fields and wrong for the record node.

Two changes, because the fix must not reopen H-04 — a read that genuinely fails still must not be
judged as blank:

- The **record id field is requested alongside** the caller's fields. It is stored for every existing
  record and is never blank, so a returned node becomes a positive fact rather than an inference. It
  also keeps the `repeat_instances` buckets in the result, which is what `resolveOne()` needs to tell
  a genuine blank from a value on another repeating instrument — without them, a blank cross-repeat
  reference resolved as a plain blank.
- A record still absent is then read by the shape of the result: an **empty** result means REDCap
  holds nothing for this record, so every field is blank and every state stays `ok`; a result
  carrying **other** records but not the one asked for is anomalous and stays `unreadable`, as does a
  non-array result.

Scope of the defect: an existing record where *every* field referenced by that page's rules was
blank — typically the first pass at a form on a record created elsewhere. New records were never
affected (REDCap passes no record, so no read happens), nor were pages whose rules reference a mix of
blank and filled fields. The post-save audit was unaffected throughout, because it runs after the
write when the values exist; it logged the violation the browser had failed to block.

`tests/hosting_php.php` gains the H-06 section: 10 checks, 6 of which fail on 1.6.0, including both
contrast cases that must keep deferring.

## 1.6.0 — cross-form `@UVASSERT`

> The first cut of this release (local tag, never pushed) was reviewed and rejected: cross-form
> `@UVASSERT` was correct only inside a narrow same-event, non-repeating envelope, and outside it
> produced false passes, false failures and false audit entries. Nine findings were filed; all
> nine were independently reproduced with executable probes before anything was changed. The
> sections below fold those fixes into 1.6.0 rather than shipping a broken tag and superseding it.
>
> Two further adversarial reviews of the repaired tree followed, each rejecting it again. The
> second found the paths that had not been moved onto the shared resolver; the third found that
> having a shared resolver was not enough, because nothing said which CONTEXTS a rule belongs to —
> see *Rules are evaluated where they live* below. Every finding of all three rounds is folded in
> here, and `tests/hosting_php.php` locks the third round's scenarios (21 of its 32 checks fail on
> the tree that was reviewed).

### Rules are evaluated where they live

The module knew how to resolve a reference *for a given context*; nothing decided which contexts a
rule belongs to. Each caller therefore chose its own, and each chose wrongly in a different way:
the scan ran every rule in every context of every record, and the save audit's reverse-dependency
pass ran a dependant in every same-event context of the record it had just read. The symptoms
looked unrelated but were one defect:

- a populated field on a repeating form reported **blank**, because the rule was also evaluated in
  the record's base row;
- a field collected only in event 1 reported **blank in event 2**;
- one rule reporting **both** "unconfigurable" and a hard violation, for one record;
- a base-form violation logged **four times** — once per unrelated repeat row of an unrelated
  instrument — and attributed to instruments the rule has nothing to do with;
- two records whose composite unique key lives on an independently repeating form reported as
  **duplicates of each other**, because unresolvable parts of the key were substituted with `''`.

`ruleHostForms()` locates a rule from the data dictionary and `hostContextsFor()` returns the
contexts that form actually occupies in a record — answered from the same signals `resolveOne()`
uses, so the two cannot drift. A base form is evaluated once per event it is mapped to; a
repeating form once per instance; a repeating event once per event instance; a form not designated
for an event is not evaluated there at all. The scan, the save audit's dependency pass and the
unique aggregator all go through it. A rule whose field cannot be located on any instrument is
**reported**, not evaluated somewhere hopeful.

The unique aggregator also runs its `when`, branch selectors and composite `uniqueWith` fields
through the resolver: an undefined pairing is refused with a stated reason instead of keyed as `''`.

### A settled condition is a snapshot, not a fact

A condition whose operands are *all* off-page was folded to a bare `["const", …]` and shipped with
its configured **hard** block intact. That constant is page-load truth: a stale `false` blocked a
valid save with no way out, and a stale `true` silently accepted an invalid one. The fold now names
the fields the constant was read from, which is what makes the rule advisory.

The same treatment now covers the applicability **gate** and branch **selectors**, not just the
assert. A stale `when` switches a rule on or off, and a stale selector decides which branch runs —
both are exactly as wrong as a stale verdict, and both previously kept the block.

### A scan may certify only what it actually read

Three more ways a scan could claim completeness it had not earned:

- the dictionary read failing while one settings rule survived — every annotation rule silently
  vanished from the list and the survivor was scanned and reported `complete`. Dictionary success
  is now established independently of whether any rule was found, and the scan cannot proceed
  without it (a rule cannot be located on an instrument otherwise);
- a record returned by REDCap with no event rows at all — zero contexts is not zero violations;
- rule **discovery** throwing, which escaped `scanProject()` entirely and produced a PHP error
  page rather than a scan result.

The scan page no longer colours an incomplete result green, offers its evidence CSV for every
executed scan rather than only when violations were found, and exports rule problems and
not-scanned reasons alongside the violations.

### A rule that stops checking says so, even with no branch to show

When every branch of a rule was deferred and there was no fallback, no variant was active and the
client fell straight through to its inert path — clearing the field silently, with the reason the
server had built for exactly this case discarded. The rule-level notice is now rendered whenever
zero variants are active *because* the rule was deferred, across all five validator kinds. An
ordinary "no branch applies here" is unchanged.

### Cross-instrument checks are ADVISORY

An off-page value is read once, when the page is built, and nothing can refresh it while the page
is open. A concurrent edit on the other form therefore makes the verdict stale — and a stale
verdict that PASSES is silent, while a stale one that FAILS was a dead end. `redcap_save_record`
runs after the write and can recover neither.

A snapshot no longer drives a save block, at any `blockSave` setting. Cross-instrument rules give
live feedback as you type, name the field they were compared against and when it was read, and the
post-save audit and Validation scan are the enforcement record. Same-instrument rules are
unchanged: both sides are live in the DOM, so nothing there is a snapshot.

### One resolver, shared by the browser, the audit and the scan

The form hooks, the save audit and the scan each worked out where a referenced value lived, and
disagreed: the scan reported a hard violation for data the save path called unconfigurable, and
neither noticed a value on a different repeating instrument when that value happened to be blank.
`resolveOne()` is now the only place that decides, and all three call it.

Ownership comes from **metadata**, never from whether a value happens to be present. REDCap omits
blank fields from `getData` output, so "the field's key is in this repeat row" answers *does it
have a value*, not *does it live here* — reading ownership off that made a blank field on another
repeating instrument look like a resolved blank. Ownership now comes from the dictionary plus
whether that form repeats (`getRepeatingFormsEvents`, then `isRepeatingForm`), with repeat-bucket
presence as a third signal; any one of them saying "repeats" is enough to refuse the pairing.

### An unresolvable branch selector no longer elects the fallback

A false `when` merely leaves a plain rule inert, but for a branched rule it **activates** the
fallback, which then enforced — flagging the field, blocking the save, and logging a violation of a
rule the designer never meant to apply. Worse, client and server picked differently: the browser
could show "OK, save allowed" while the same save logged a violation of a *different* branch.
Branch selection now consults the resolution first and refuses the whole decision if any selector
is unresolved. This is shared machinery, so it covered `@UVREQUIRED` too — that factory had no
notion of deferral at all and now honours it.

### A scan that did not finish cannot look clean

`scanProject` returns `complete` / `incomplete` / `failed`. A chunk that fails or throws, a record
that was requested but not returned, a record-list read that fails, and a dictionary failure are all
recorded instead of skipped in silence. The page shows a banner and refuses the green tick, and the
CSV carries an `# INCOMPLETE SCAN` header — a downloaded "0 violations" from a partial pass would
otherwise circulate as a clean result.

### Ordering is defined only within a domain

Choosing the comparator per PAIR made ordering non-transitive: `"2" <= "10"`, `"10" <= "1e1"` and
`"2" > "1e1"` were all true at once, because `1e1` fails `NUM_RE` and fell to byte order. Ordered
comparisons now require both operands in the same domain — both numeric or neither — and are false
otherwise, whichever way round they are asked, so no cycle can form. Equality is untouched. Blank is
exempt: it is absence, not a rival domain, so `[end_date]>=[start_date]` with `start_date` not yet
entered still passes rather than inventing a violation.

Verified independently: 4374 verdicts across 27 operand shapes, **0 PHP/JS disagreements**, and
**0 ordering cycles** across 19,683 triples.

### Resolution is now three-state, not "value or blank"

Four findings (H-01, H-04, M-01, M-03) had one root cause: `readValues()` could not distinguish
**"resolved to blank"** from **"could not be resolved"**. Both arrived as "absent from the value
map", which `Logic::operandValue()` renders as `''` — so the module confidently validated against
a value it had never read. It now reports one of four states per field:

| state | meaning |
|---|---|
| `ok` | located; the value may legitimately be empty |
| `missing` | the field's form is not designated for this event (`M-01`) |
| `ambiguous` | the field lives in a **different repeating instrument** (`H-01`) |
| `unreadable` | `getData` threw, returned a non-array, or the record was absent (`H-04`) |

Anything other than `ok` means *no answer*: `fold()` refuses to bake it, marks the rule
`deferred`, and records **why**. The browser states no verdict and never blocks; the save audit
and the Validation scan skip the rule and emit an `unconfigurable` note naming the field and the
reason, instead of logging a violation for correct data on every save and every scan. The guard
covers the `when` gate as well as the `assert` — a gate evaluated against a value that was never
read turns a rule silently off, which is no better than turning it silently on.

`unreadable` is stronger still: an empty value map is indistinguishable from "every field is
blank" for **every** rule kind, not just constraints — `@UVREQUIRED` would report a populated
field as blank and a check rule would pass an invalid ID — so a failed read aborts the whole
audit for that save with a logged error rather than auditing data the module does not have.

A **genuinely saved-blank** reference still resolves `ok` and still bakes as `['lit','']` — that
distinction is the whole point, and it is pinned by tests.

**Cross-repeating-instrument references are refused, not guessed.** Instance 3 of one repeating
form has no defined counterpart in another; REDCap itself requires explicit
`[instrument][instance]` smart variables to cross that boundary. Guessing by instance number
would be silently wrong whenever the two forms are not created in lockstep, and indistinguishable
from a real violation. Still fully supported: repeating → non-repeating, the base event row, two
fields on the **same** repeating instrument, and repeating **events**.

`missing` is derived from the project's instrument-event mapping — a positive fact — never from
mere absence of data, which is just a blank. Where the mapping cannot be established the module
does **not** claim `missing`, which fails open to previous behaviour rather than deferring rules
wrongly.

### Editing the referenced form is no longer unguarded (H-02)

A cross-form constraint lives on the instrument carrying the tag, so breaking the relationship by
editing **only the referenced side** was completely silent: that form installs no client
validator, and the audit's instrument scope excluded the dependent rule. `redcap_save_record` now
also audits **reverse dependencies** — rules whose own field is not on the saved instrument but
whose `assert`/`when` references a field that is. Scope is widened per-rule only, so **PER-001
still holds**: an unrelated instrument with no dependants reads no data and audits nothing, which
its test asserts explicitly.

### Numeric comparison is exact decimal, never float (H-03)

`9007199254740992 = 9007199254740993` returned **true** in both runtimes, so the documented
`@UVASSERT="[id]=[id_confirm]"` recipe accepted two different identifiers. Every numeric-looking
operand was cast to IEEE-754, losing precision above 2^53 and on long fractions. `Logic::decCmp`
and its twin `QRID_whenDecCmp` now compare decimal strings exactly — no float, no `bcmath`, no
`BigInt`. Documented equivalences are unchanged (`02` = `2`, `2.50` = `2.5`, `.5` = `0.5`,
`-0` = `0`), and values `NUM_RE` rejects (`1e3`, `0x1F`) still take the string path as before.

### Blank means the same thing on both sides (M-04)

The browser went inert on any JS-trimmable host value while the server only short-circuited on
exact `null`/`''`, so a whitespace-only entry showed no verdict yet logged a violation. Both now
use the charlist the two evaluators already trim with before comparing (`" \t\r\n"`).

### A stale snapshot is now explicable (M-02)

Off-page operands are resolved once, when the page is built. If someone edits that form in
another tab the verdict here goes stale, and a wrong **hard** block was a dead end with no
explanation. A failure now names the field it was compared against and says the value was read
when the page was opened, so the user can reload. Survey respondents still get generic wording.

### A deferred rule now says why

The reason a rule stopped checking was built on the server and thrown away: nothing in the engine
ever read `deferredWhy`, because a deferred rule short-circuits before any message is composed. A
rule that goes quiet with no explanation is the same silent failure the reason exists to prevent,
so staff data-entry forms now show it as a neutral notice — never a pass/fail verdict, never a
block. Survey respondents still see nothing: the reasons name other instruments and fields, and a
respondent cannot act on a design problem anyway.

Reasons are attributed **per rule**. The first cut collected them page-wide and attached the whole
list to whichever rule deferred first, so that rule was blamed for fields its condition never
mentioned while the rule whose problem it actually was got nothing.

### Deferred rules are DETECTION, not enforcement (H-05)

`redcap_save_record` fires **after** the write — its own docblock has always said so — and cannot
prevent persistence by any channel. The previous release notes and documentation called the
deferred path "enforcement" and claimed "deferring costs live feedback, never enforcement". That
was materially false: for a survey respondent, or a user without rights to the referenced
instrument, a `blockSave:"hard"` rule is demoted to `off`, the invalid value **is written**, and
the audit logs it afterwards. Every such claim across README, the user guide and the action-tag
examples has been rewritten to say plainly: the save is accepted, the audit records it, the scan
can find it later.

---

### The original 1.6.0 change, for context

A constraint whose `assert` referenced a field on **another instrument** was folded whole to a
boolean at page load, freezing the verdict against the **saved** value of the field the user was
about to type into. `Logic::fold()` did this deliberately — shipping the other form's value would
reopen SEC-005 — but the consequence was never measured, and it is wrong in **both** directions:

- The asserted field **blank** at load folded `"" >= "…"` to `false`, so typing a **correct** value
  produced an error that **hard-blocked the save** and that no amount of retyping could clear.
  Cross-form data entry was impossible.
- A value that was **valid** at load froze to `true`, so it could then be replaced with anything at
  all and the browser still showed "OK" and let the save through.

Both reproduced on live REDCap 17.0.6 (pid 149, `umt_offref` → `uht_code`): the deployed module
shipped `assertAst: ["const", true]` and a deliberately wrong value showed "✓ OK.".

**The fix.** A comparison mixing a live and an off-page reference is now kept **live** by replacing
the off-page ref with a `['lit', <value>]` operand — a node the parser already produces for the
designer's own literals, so both evaluators and the ref-collector handle it unchanged and the
browser watches only the field it can actually see.

That value is baked in **only when the viewer is already entitled to read it**
(`disclosableFields()`): data entry only — never a survey page — an authenticated username, and
REDCap form-level rights to the referenced instrument. Every gate fails **closed**; a field the
viewer may not read discloses nothing, exactly as before. This is the same information REDCap's own
branching logic already ships to the page.

When the value is **not** disclosable the old fold stands and the rule is marked `deferred`: the
client states **no verdict and never blocks** (a stale verdict is wrong both ways, and a frozen
`false` blocking a correct entry is the bug above), while `redcap_save_record` still enforces the
constraint and logs any violation as `type: constraint`. Deferring costs live feedback, never
enforcement.

- `php/Logic.php` — `fold()` takes `$disclosable` and reports `$frozen`.
- `UniversalValidator.php` — `disclosableFields()` / `userFormRights()` / `currentUsername()`;
  `foldRuleConditions()` threads the page context and marks deferred rules (branches included).
- `js/engine.js` — `deferred` joins `DEFAULT_KEYS`; the constraint factory forces `blockSave` off
  and states no verdict for a deferred rule.

**Rights are read through the framework-native `User::getRights($pid)` first.**
`\REDCap::getUserRights()`'s FIRST parameter is the user list, not the project id, so
`getUserRights($pid)` would silently return rights for a user named after the pid — the feature
would go quietly inert on a real REDCap while every mock passed, exactly how `@UVUNIQUE` shipped
dead in 1.4.0. The static is only a fallback, and is called with **no arguments** so the parameter
order cannot be got wrong, then filtered by username here.

Tests: `tests/crossform_php.php` (new, 41 checks) covers the entitled path, both un-entitled paths,
three fail-closed paths, read-only rights, script-breakout of a hostile baked literal, that a
deferred rule is still audited, **both rights sources reaching the same verdict**, and that a
pid-keyed rights table grants nothing. `tests/crossform_adversarial_php.php` (new, 44 checks)
red-teams the edges: checkbox refs, `$frozen` escaping and/or/not nesting, a condition mixing a
disclosable and a non-disclosable ref, per-branch deferral, a frozen `when` with a live assert,
condition-text de-duplication, empty values, repeating instances, unknown refs, and the other four
rule modes. `tests/constraint_dom_js.cjs` gains 22 checks (live cross-form contract, the deferred
contract, per-branch deferral, and mode composition). `tests/when_fixture.json` gains six
cross-runtime cases pinning the `['lit', …]`-substituted operand.

Every gate was mutation-tested — survey gate, rights level, fail-closed catch, `$frozen`,
the disclosable check, the literal substitution, `deferred` in `DEFAULT_KEYS`, and the client
`deferred` short-circuit — and each mutant is caught by at least one check.

**Deploy both `UniversalValidator.php` and `js/engine.js`.** The deployed engine ignores `deferred`
(verified live), so a PHP-only deploy fixes the entitled path but leaves the un-entitled path
hard-blocking as before.

Docs updated for the new capability and the two limits: the `@UVASSERT` and `when` sections of
`README.md`, the `@UVASSERT` summary / `when` semantics / "Referencing a field on another
instrument" recipe in `docs/action_tag_validation_examples.md` (which now separates the two
comparison shapes — off-page-vs-literal still settles at page load, off-page-vs-on-form is live),
and a new cross-instrument entry in the `docs/USER_GUIDE.md` FAQ. Every `@UVASSERT` condition
printed in the docs was re-parsed with the module's own dialect parser (88 conditions, 0 errors).

Still **not** supported, and now stated plainly rather than failing silently: a reference to a field
on **another event**. `readValues()` scopes `getData` to the hook's `event_id`, so an off-event ref
reads `''` and the assert passes — in the browser *and* in the audit. Keep both fields in one event.

## 1.5.8 — hardening from an adversarial red-team of the 1.5.3–1.5.7 fixes

A red-team / wargaming pass over every 1.5.3–1.5.7 change (each candidate independently
refutation-tested) found six real defects in those fixes; all are fixed here.

- **F1-1 (the F1 ReDoS gate).** The bounded-quantifier stage two-b run was ended by ANY `choices<2`
  token, so a bare atom of the SAME class as the surrounding repeats reset the product mid-chain:
  `[0-9]{1,20}` ×4, a lone `[0-9]`, then `[0-9]{1,20}` ×4 passed the gate (two 4-factor runs, each
  under the budget) yet froze the browser (~9 s at 44 chars). A run now ends only at a genuine split
  — an UNBOUNDED quantifier or a class DISJOINT from the run — and an overlapping fixed atom bridges
  on (choices 1); the run's class is tracked forward so a bridging chain is measured whole.
  Twinned JS + PHP.
- **F2-BYPASS-01 (the F2 dialect guard).** The guard missed `\x{...}`, the brace-form sibling of
  `\u{...}` (PCRE `/u` reads `\x{41}` as 'A'; the browser without the `u` flag reads it as `x{41}` =
  41 x's). `\x{` is now rejected alongside `\p{ \P{ \u{ \k<`, in all three sites.
- **F2-OVERREJECT-02.** The same guard used naive substring scans and wrongly rejected a legitimate
  literal-backslash pattern like `\\u{2}`. It now strips escaped-backslash pairs first, so only a real
  `\`-escape is caught. (Shared `usesUFlagEscape` / `QRID_uFlagEscape` helper.)
- **F4-DAG-01 (the F4 filterLogic narrowing).** For a dag-scoped rule, the narrowed live query dropped
  the current record from the result, so a no-DAG acting user's DAG could not be resolved and the
  check silently degraded to project scope, falsely flagging a cross-DAG "already used". Narrowing is
  now skipped for dag scope (the full scan resolves the DAG correctly); it still applies to
  project/event scope.
- **L01-UTF8-COLLAPSE (the L-01 scan key).** `json_encode` with `JSON_INVALID_UTF8_SUBSTITUTE`
  collapsed distinct invalid-UTF8 values to U+FFFD, so two different stored values could read as a
  false duplicate in the scan (while the byte-exact audit did not). The scan key is now a lossless
  `bin2hex` join.
- **UV-1553-01 (the M-05 scan transparency).** The config-error notices were attached only on the
  normal path, so a scan of a project with zero scannable records (empty/new project, a DAG with no
  records, a transient read failure) dropped them and implied a clean project. They are now attached
  before those early returns.
- **Tests.** `tests/risky_patterns.json` gains the F1-1 reset-trick (risky) + disjoint-separated
  controls (safe); `annotation_php` 144→146 (`\x{}` rejected, literal-backslash accepted); `hook_php`
  273→278 (F4-DAG-01 dag-vs-project narrowing, invalid-UTF8 non-collapse, zero-record config-error
  surfacing). Full JS + PHP 7.4 / 8.3 suites green. The F5 concerns from the same review were verified
  to be defense-in-depth / deployment-dependent and left as documented.

## 1.5.7 — sessionless rate-limit tier and a pooled-ambiguity guard (F5, M-03)

- **F5 — the unauthenticated throttle now bounds a sessionless flood.** v1.4.1's throttle counted
  only in `$_SESSION`, so a cookieless (or cookie-rotating) caller — the actual sessionless vector —
  was never counted. `surveyRateLimited` now has two tiers: a per-session window for a caller that
  carries a session (a normal survey respondent, unchanged), and, when there is NO session, a
  per-PROJECT sliding window (600 / minute) in a single hard-capped, self-pruning system setting.
  Legitimate respondents carry a session and never reach the project tier, so it adds no write load
  or false throttling to normal traffic; both tiers fail open. This bounds call VOLUME; F4 (1.5.6)
  bounds per-call cost, and the survey opt-in stays off by default and refused on Identifier fields.
- **M-03 — reject an ambiguous regex-only pooled count.** A regex-only pooled field (algorithm
  "none", so no check character to disambiguate a split) with a VARIABLE ID length and an
  `expectedIds` count is inherently ambiguous — a run can divide into different numbers of members,
  so the parser would pick one division and could misreport the count. That combination is now a
  config error naming the fix (a single exact length, drop `expectedIds`, or a check algorithm, which
  disambiguates by verification). Check-mode and single-exact-length configs are unaffected.
- **Tests.** `tests/annotation_php.php` 141→144 (M-03 error + two controls); `tests/hook_php.php`
  270→273 (F5 per-project budget throttles a sessionless caller at the cap; a fresh caller is
  answered and recorded). Full JS and PHP 7.4 / 8.3 suites green.

## 1.5.6 — bound the live uniqueness query (F4)

- **F4 — the live endpoint no longer exports the whole project on every call.** `findCollision` on
  the unauthenticated (survey) uniqueness path — reachable repeatedly by anyone once a field is
  opted into survey checks — read every record's value for the checked field(s) on each request, so
  a scripted walk of an ID space amplified a tiny request into a full-project data export. The live
  endpoint now narrows the read with a REDCap `filterLogic` built from the candidate value(s), so the
  database returns only the few records that could collide. Best-effort and fail-safe: a value that
  cannot be safely inlined (a quote, a bracket, an operator) or a build that does not honor
  `filterLogic` falls back to the full read, and the exact PHP comparison plus the post-save audit
  (which never narrows) stay authoritative — the narrowing can only save work, never turn a real
  duplicate into a missed save.
- **Tests.** `tests/hook_php.php` 265→270: the endpoint sets a `filterLogic` on a safe candidate
  value and still finds the collision; an unsafe value (with a quote) falls back to the full scan and
  still finds it; the post-save audit never narrows. Verified to fail without the fix. Full JS and
  PHP 7.4 / 8.3 suites green.

**Note:** this bounds the *cost per call*; an effective sessionless *rate limit* on the no-auth path
(F5) is still open and would bound call *volume*. The survey opt-in stays off by default and is
refused on Identifier fields, so the endpoint answers at all only for a designer-nominated,
non-identifying field.

## 1.5.5 — per-form rule injection, and two client/server parity fixes (F9, F6, F7)

Three fixes from the pre-submission review — the performance one is server-side, the two parity ones
adjust the client to match the server.

- **F9 — inject only the rendered instrument's rules (performance).** The module injected EVERY
  project rule on EVERY data-entry form and survey page, and each injected rule installs its own
  `document.body` MutationObserver — so a rule-heavy project (the 1.5.1 note measured ~69 rules)
  stacked dozens of observers on every form, and a single DOM mutation (e.g. a checkbox click)
  fanned out to all of them and froze the tab for seconds. `buildClientConfig` now filters the
  injected rules (and each rule's field list) to fields on the instrument being rendered, so a form
  carries a validator/observer only for its own fields. Coverage is unchanged — the post-save audit
  and the Validation scan still run every rule over every record — and config-error rules are kept
  so their notice still surfaces.
- **F6 — pooled `idLengths` deduplicated on the client (parity).** The browser kept duplicate
  `idLengths` while the server `array_unique`'d them, so the per-rule scan cap was computed from a
  different `|LENS|` on each runtime: a mid-length pooled value got no client verdict yet was still
  audited server-side. The client now dedups (and integer-normalizes) to mirror `pooledState`.
- **F7 — client `when`/`assert` ordering compares by code point (parity).** JS `<`/`>` compare
  UTF-16 code units, which order astral (> U+FFFF) characters differently than the server's `strcmp`
  (byte order = code-point order); an ordered comparison of a non-ASCII field value could hold on
  one runtime and not the other. The client now compares by code point for `< > <= >=` (equality was
  already exact). ASCII / BMP results are unchanged.
- **Tests.** `tests/hook_php.php` 261→265 (F9 per-instrument rule + field filtering, including a
  cross-form rule); `tests/when_fixture.json` gains four astral-ordering cases locked across both
  runtimes (`when_js` 114→122, `when_php` 142→150, verified to fail without the F7 fix); F6 parity
  confirmed by a cross-runtime probe (a mid-length duplicate-length value now parses on both). Full
  JS and PHP 7.4 / 8.3 suites green.

**Not done (disclosed):** consolidating the seven per-factory MutationObservers into one shared
observer — the other half of the 1.5.1 perf note. The DOM test harness does not implement
`MutationObserver`, so that client rewrite cannot be verified here; it is deferred to a live/browser
pass. F9's per-form filtering already removes the documented freeze by cutting the observer count on
any form to that form's own rules.

## 1.5.4 — Validation scan transparency and a collision-free scan key (M-05, L-01)

Two lower-severity fixes from the second independent review. Scan-page reporting only — no rule's
accepted-value behavior changes.

- **M-05 — the Validation scan now discloses rules it could not enforce.** A rule with a
  configuration error was silently dropped from the scan, so a broken rule read as "no violations
  found." The scan now lists every config-error rule (and any unique-rule branch conflict or
  unparseable branch condition hit during the sweep) in its "rule problems" section — the module's
  rule is that nothing fails silently, and a scan operator must know when a rule is inert. Config-
  error rules still produce no phantom violations; live rules are unaffected.
- **L-01 — the scan's composite-duplicate key is now collision-free.** The aggregate uniqueness pass
  joined key components with a raw `0x1F` byte, so two distinct tuples whose values happened to
  contain that byte could share a key and be reported as a false duplicate. The key is now a
  `json_encode` of the component array (which escapes every byte), so distinct tuples never collide;
  genuine duplicates are still detected.
- **Tests.** `tests/hook_php.php` 256→261: a mixed live / config-error scan (the config error is
  surfaced, produces no phantom violation, and the live rule still runs), and the `0x1F`
  key-collision case (distinct tuples are not a false duplicate, a genuine duplicate is still
  caught). Both verified to fail without the fix. Full JS and PHP 7.4 / 8.3 suites green.

## 1.5.3 — pre-submission review fixes: client ReDoS residue, regex-dialect parity, identifier oracle gaps, cross-instrument uniqueness

Addresses the three Medium findings from
`reports/presubmission-adversarial-review-2026-07-18.md` (a source-level adversarial pass, each
reproduced by execution), plus H-01 and H-03 from a second independent review. No rule's
accepted-value behavior changes; the fixes tighten config-time rejection, close the unauthenticated
survey-oracle gaps, and make live/audit/scan uniqueness agree on composite keys.

- **F1 — client tab-freeze from a bounded-quantifier chain (security / availability).** The
  `riskyPattern` gate reasoned only about UNBOUNDED quantifiers, so a flat chain of overlapping
  BOUNDED quantifiers — `A{1,20}A{1,20}A{1,20}A{1,20}A{1,20}A{1,20}!` — passed both stages and then
  ran in the browser's backtracking engine on every keystroke, the factor count acting as the
  exponent (measured: ~5 factors of `{1,20}` = 130 ms, ≥6 froze the tab). The server has a
  match-time PCRE-error backstop; the client had none. New stage **two-b** bounds the PRODUCT of
  per-factor match-length choices across a contiguous run of overlapping bounded repeats at
  `MAX_BACKTRACK_PRODUCT` (1,000,000) — chosen by measurement so the deliberate 3-factor residue
  `A{1,40}A{1,40}A{1,40}9` (64,000, ~3 ms, which still exercises the server's match-time guard)
  passes and the freezing chains do not; the worst admitted case is ~40 ms. A fixed atom, an
  unbounded quantifier, or a disjoint class ends the run and anchors a split, so disjoint ID formats
  (`[A-Z]{2}[0-9]{4}`) are untouched. The `QRID_polyOverlap` / `CheckCharacter::polynomialOverlap`
  twins stay behavior-identical, locked by `tests/risky_patterns.json`.
- **F2 — regex-dialect parity break on `\p{}` / `\u{}` / `\k<>` (correctness).** The client compiles
  an `idPattern` WITHOUT the JavaScript `u` flag while the server compiles PCRE with `/u`, so a
  Unicode-property escape validated oppositely: `\p{Nd}{3}` matched `"123"` on the server but not in
  the browser, blocking a valid ID live while the audit and scan passed it. These escapes are
  all-ASCII, so the printable-ASCII guard let them through. They are now rejected at config time in
  both channels — the same way `\A` / `\Z` and `(?P<...)` already are — keeping the proven-parity
  subset to explicit character classes.
- **F3 — identifier existence-oracle fail-open (security, defense in depth).** The unauthenticated
  survey uniqueness check refuses Identifier fields via `projectIdentifierFields()`, which returns
  null when the data dictionary momentarily cannot be read; `isIdentifier(null, …)` is false, so a
  transient dictionary failure silently reopened the existence oracle (a settings-channel unique
  rule with the survey opt-in survives a null dictionary, and `findCollision()` needs no dictionary
  to answer). The endpoint now FAILS CLOSED when identifier status is unverifiable — an
  unauthenticated caller loses only the live convenience, and the post-save audit and the Validation
  scan still cover the field.
- **H-01 — Identifier refusal now covers composite `with` fields (security, sibling of F3).** The
  survey opt-in was refused only when the PRIMARY unique field was an Identifier; a composite key
  such as `@UVUNIQUE={"surveys":true,"with":["date_of_birth"]}` on a non-identifier primary let the
  identifying `date_of_birth` value be compared on the unauthenticated route. All three gates (both
  config channels and the endpoint) now refuse the opt-in when the primary field OR any `with` field
  is an Identifier, via a shared `firstIdentifier()` helper; the endpoint re-check is defense in
  depth behind the config gates.
- **H-03 — cross-instrument / repeating composite uniqueness now agrees across live, audit, and
  scan (correctness).** A composite `@UVUNIQUE` whose `with` field lives on a different instrument
  (or repeating context) than the primary field had three different verdicts: the live check sent
  `""` for the off-page `with` field and read "available"; the post-save audit's `findCollision`
  compared each other record within a single raw row and MISSED a composite split across an event
  node and a repeat row; only the scan (which merges rows) caught it. Two fixes, both server-side
  and privacy-preserving: (1) `findCollision` now compares each other record's MERGED contexts
  (`recordContexts` — base event row + each repeat instance), the exact view the scan uses, so the
  audit and scan can no longer disagree; (2) the live endpoint resolves an off-instrument `with`
  field's saved value on the server (a field on the rendered instrument keeps the browser's live
  value), so the composite is complete without ever sending an off-page value back to the page.
  Same-instrument composites are unaffected.
- **Tests.** `tests/risky_patterns.json` gains the bounded-chain class in `risky` and the residue
  plus a disjoint-alternation in `safe` (locked across both runtimes by `risky_js` / `risky_php`,
  50→56 patterns); `tests/annotation_php.php` adds the F1 chain rejection, the residue-still-passes
  control, and the F2 `\p{}` / `\u{}` dialect errors (136→141); `tests/hook_php.php` adds the F3
  anonymous-caller fail-closed case, the H-01 composite-Identifier refusal across all three gates
  with a non-identifier control, and the H-03 cross-instrument composite agreeing across audit /
  live endpoint / scan (246→256, verified to fail without each fix). Full JS and PHP 7.4 / 8.3
  suites green.

## 1.5.2 — renamed to "Universal Field Validator"; tightened the module description

Presentation only — no functional change, no rule behaves differently.

- **Renamed** from "Universal Regex & Check-Character Validator — IDs, codes &
  patterns" to **"Universal Field Validator — check-character & regex IDs,
  cross-field rules, uniqueness & dynamic choices"**. The old name framed the
  module as ID validation; four of its five modes (constraints, required,
  uniqueness, dynamic choices) are not about IDs. The `INSPIRE\UniversalValidator`
  namespace and the module directory are unchanged, so this is a display-name
  change with no deployment impact. The browser-facing strings (the
  configuration-error box title and console messages in `js/engine.js`), the
  README and user-guide titles, and the class docblock were updated to match;
  historical CHANGELOG entries keep the name they shipped under.
- **Description rewritten** shorter (~330 → ~190 words) and made scannable: each
  of the five tags gets a one-line "what it does", and the **Validation scan** is
  now called out as its own capability (it was previously a clause buried in the
  first sentence) — a post-save audit plus an on-demand project scan that
  re-checks every saved record, covering values entered by API, Data Import, or
  before a rule existed, with CSV export.
- The five action-tag helper entries (shown in the Online Designer) were already
  complete; no change there.

## 1.5.1 — checkbox state was unreadable on REDCap 17 (live-found, pid 149)

**Bug fix. Anyone using a checkbox in a `when`/`assert` condition, or filtering a
checkbox with `@UVCHOICES`, should take this release.** Found on the first live
run of 1.5.0 on REDCap 17.0.6.

- **The defect.** For each checkbox option, REDCap 17 renders TWO elements: a
  **hidden** input named `__chk__<field>_RC_<code>` (its VALUE is the code when
  checked, `""` when not — `type=hidden`, so its `.checked` is always false) and
  the **visible** clickable `<input type=checkbox>` carrying id
  `id-__chk__<field>_RC_<code>` (shared name `__chkn__<field>`). The engine read
  `.checked` off the element it found **by name** — the hidden mirror — so a
  checked box read as **unchecked**. Consequence: every `[field(code)]` checkbox
  reference evaluated false regardless of the real state. A cascade gated on a
  checkbox (e.g. `@UVCHOICES={"when":"[pilot(1)]='1'",…}`) never activated; a
  checkbox `@UVASSERT`/`@UVREQUIRED`/`@UVUNIQUE` `when` never fired; a
  checked-but-hidden `@UVCHOICES` code was never detected as stale.
- **The fix.** A single `QRID_readCheckbox(field, code)` now resolves the state
  across renderings: a `__chk__…_RC_code` that IS a checkbox → its `.checked`
  (classic REDCap); one that is `hidden` → `value === code` (17.x mirror); else
  the visible `id-__chk__…_RC_code` checkbox → its `.checked`. `readRef` routes
  every checkbox reference through it, `requestField` also binds change/click on
  the visible `__chkn__<field>` (the hidden mirror fires no events), and the
  `@UVCHOICES` renderer computes "is this option checked" the same robust way so
  a checked option is never hidden from under the user.
- **Why the tests missed it.** The DOM stub modeled `__chk__…_RC_code` as a real
  checkbox with a working `.checked` — more forgiving than REDCap 17. The stub
  now models the 17.x two-element structure (hidden mirror + visible box in one
  choicevert row); `tests/choices_dom_js.cjs` gained a checkbox-ref-gated
  cascade and a checked-hidden-stale case (58→67 checks) that FAIL on the old
  code and pass on the fix (verified by reverting). Backward compatibility with
  the classic single-checkbox rendering is retained and still covered.
- Radio, dropdown, the two-level cascade, stale-kept selections, and the
  save-block (off/confirm/hard) were all verified working live on the same run.

### Known issues (not fixed here)

- **Performance on rule-heavy projects.** A project injecting ~69 rules made a
  checkbox click freeze the page for tens of seconds live (each rule installs its
  own `document.body` MutationObserver; a click that mutates the DOM fans out to
  all of them). Pre-existing and module-wide, not specific to choices mode —
  tracked separately. Candidate fix: inject only rules whose fields are on the
  rendered instrument, and share one observer.
- **Checkbox message placement.** A `@UVCHOICES` message on a checkbox field is
  anchored inside the first option row; a show/hide list that hides that first
  code could hide the message with it. Narrow (typical cascades keep the first
  code shown); to be re-anchored above the option rows in a later release.

## 1.5.0 — dynamic choice filtering: the @UVCHOICES tag (choices mode)

A fifth rule mode. REDCap's `@HIDECHOICE` hides options statically;
`@UVCHOICES` shows/hides individual options of a **radio, dropdown or
checkbox** field while a REDCap-style condition holds — cascading
country → region → site lists in one field instead of a near-duplicate field
per country.

- **Grammar.** JSON form only, exactly one of `show` (whitelist — the
  complement of the field's own choice list hides) or `hide` (blacklist) per
  tag, plus optional `when`, `message`, `blockSave`. Repeated tags with
  different `when` conditions branch through the existing `Branching`
  machinery (one tag per country, at most one unconditional fallback); no
  active branch means no filter. Codes are validated against the field's
  `select_choices_or_calculations` at rule-build time; unknown codes,
  non-choice field types, and matrix membership are per-field config errors.
- **A hidden selection is never cleared.** A currently-selected choice that
  becomes hidden stays visible (dropdowns keep it in place, disabled), the
  field is flagged invalid with the message, and `blockSave` (off/confirm/
  hard) runs through the shared save guard. Values outside the field's choice
  list (missing-data codes) are out of scope on both runtimes.
- **Plumbing.** Rules carry `choicesAll` (the full code list, attached from
  the data dictionary) so the client computes a `show` whitelist's complement
  without DOM enumeration — checkbox options are only findable by exact
  `__chk__<field>_RC_<code>` name. `choicesAll` participates in the
  `groupMulti` canonical key, so identically-tagged fields with different
  choice lists never merge into one rule. `projectFieldChoices()` now
  enumerates radio and dropdown rows too (previously checkbox-only;
  `Logic::checkRefs` is unaffected — it only consults checkbox entries).
- **Client.** New `QRIDChoiceFilterInit` factory (same variant/gate/boot
  skeleton as required mode, own guard item, composes with the other modes).
  Dropdown filtering physically removes and re-inserts `<option>`s in
  original order — Safari ignores `hidden`/`display:none` on options; radio
  and checkbox options hide their wrapper element. Live re-evaluation rides
  the shared when-registry.
- **Audit + scan.** `ruleFindings` gains a `choices` block: a saved value
  (or checked checkbox code — the one mode that judges checkbox arrays) that
  the active filter hides logs `type: choices`, `reason: hidden-choice`; the
  Validation scan reports the same verdicts unchanged.
- **Tests.** `tests/choices_php.php` (37 checks: grammar, errors, grouping,
  branching), `tests/choices_dom_js.cjs` (44 checks: remove/restore order,
  stale-kept semantics, conflict, survey muting, blocking), and
  `tests/choices_fixture.json` — the hidden-set contract consumed by BOTH
  runtimes (`hook_php.php` drives every fixture case through the real audit;
  the DOM test through the real factory). `tests/hook_php.php` 210→246.
  Full existing suite green (no regressions).

## 1.4.3 — the v1.4.1 survey guards were bypassable by omitting a parameter

**Security fix. Anyone running 1.4.1 or 1.4.2 with an `@UVUNIQUE` rule should
take this release.** Found by adversarial review of 1.4.1 itself: the hardening
it added did not defend the path that actually mattered.

- **The defect.** `unique-check` is declared in `no-auth-ajax-actions`, so the
  endpoint is reachable with **no session at all**. v1.4.1 decided "is this
  caller untrusted?" from `$survey_hash` — a value the *caller* supplies. An
  unauthenticated request that simply **omitted the hash** got
  `$isSurvey === false` and skipped every guard added in 1.4.1: the
  `surveys` opt-in requirement, the Identifier refusal, and the rate limit. The
  endpoint then answered `used: true/false` for **any** field carrying a live
  unique rule — including a field flagged `Identifier?`, and including rules
  whose designer never opted surveys in. An unauthenticated, unthrottled
  existence oracle ("is this national ID enrolled?") — precisely what 1.4.1 was
  written to prevent, defeated by leaving a parameter out.
- **The fix.** The guards now key on **authentication** (`$user_id`), the only
  value here that means REDCap authenticated the caller; a survey hash proves
  nothing. Any unauthenticated caller — survey page or bare HTTP — must pass the
  opt-in, the Identifier refusal and the throttle. The colliding record id still
  requires an authenticated, non-survey request (and the DAG check).
- **Why the tests missed it.** They covered `(survey_hash, no user)` and
  `(no hash, staff user)` but never `(no hash, no user)` — the unauthenticated
  caller. `tests/hook_php.php` now exercises that exact shape: an anonymous
  request is refused on an Identifier field and on any rule without the opt-in,
  answers boolean-only on an opted-in non-identifying field, and the same field
  still answers a staff session in full. Verified by reverting the fix and
  watching the new checks fail.
- `tests/hook_php.php` 205→210.

## 1.4.2 — @UVUNIQUE was inert on a real REDCap (live-found)

Found on the first live run of v1.4.0 (pid 149, REDCap 17.0.6): every rule kind
parsed and attached correctly, but the injected config carried **no
`jsmoName`** on a form with three unique rules — so the browser had no AJAX
transport and the live duplicate check did nothing at all. Silently. The
post-save audit and the Validation scan still caught duplicates, so no data was
wrong; the headline as-you-type check simply never ran.

- **Root cause.** The transport was guarded with
  `method_exists($this, 'initializeJavascriptModuleObject')`. The External
  Modules framework exposes those methods through
  `AbstractExternalModule::__call()`, and **`method_exists()` returns FALSE for
  a magic-proxied method** — so the entire block was skipped, with no exception
  to notice. Now guarded with `is_callable()`, which honours `__call()` and is
  true for a directly-declared method too. Both framework shapes work.
- **Why no test caught it.** The test stub *declares* the methods, so
  `method_exists()` was true in the mock and false in production — the mock was
  more permissive than reality. `tests/hook_php.php` now carries a
  `ProxyJsmoModule` that serves both methods **only** through `__call()`,
  exactly as the real framework does, so this class of mistake cannot return.
- **The empty catch was the other bug.** A missing transport was swallowed,
  which is precisely what hid the diagnosis and violates the module's own rule
  that nothing fails silently. A missing/failing JSMO now logs
  `uvalidate-no-unique-transport` with the reason and the consequence ("the live
  duplicate check is inert on this page; the post-save audit and the Validation
  scan still apply"). The client still fails open and never traps a save.
- `tests/hook_php.php` 197→205.

## 1.4.1 — the survey uniqueness check is refused on Identifier fields

Closes the one advisory from the 15 Jul 2026 security scan (v1.4.0: **0 errors**,
one warning). The scanner flagged the module's `no-auth-ajax-actions` and asked
us to confirm two things. The first was already true — the `unique-check`
payload is allow-listed (field name validated, answered only for fields
carrying a live unique rule, scope/composite/opt-in re-derived from stored
rules, payload capped). The second — *"a survey-side uniqueness reply does not
expose sensitive record existence"* — **could not honestly be confirmed**: an
"already used" answer to an unauthenticated respondent IS record-existence
disclosure. That is inherent to the feature, and opt-in + boolean-only limits
the blast radius but does nothing about a TARGETED probe ("is this national ID
enrolled?"). So the guard is no longer left to the designer's reading:

- **Refused on Identifier fields.** REDCap already knows which fields identify
  a person, so `surveys:true` on a field flagged `Identifier?` is now a
  configuration ERROR in both channels, not a warning — enforced again at the
  endpoint (defence in depth), and staff-side uniqueness on those fields is
  unaffected.
- **The unauthenticated path is rate-limited** (30 checks/minute/session,
  fail-open when there is no session). Honest about scope: this blunts a script
  walking an ID space; it is not a defence against a targeted probe or an
  attacker who clears cookies — the Identifier refusal is.
- **The opt-in label now says what is true**: "anyone holding your survey link
  can test whether a specific value is already in this study", with the
  reasonable use (a non-identifying response token) named, instead of the old
  euphemism "record-derived information".
- **Fixed a fail-open the guard itself introduced** (caught by PHP warnings, not
  by a passing test): the dialog channel resolved the identifier map inside
  `settingRowToRule`, which has no project id and would fall back to
  `getProjectId()` — null on import/API contexts (SEC-002), so the dictionary
  read would come back empty and the guard would silently pass. The map is now
  passed in from the caller's explicit pid, like `$types`/`$choices`, with a
  regression test that models a null `getProjectId()`.
- Also: the Control Center **description** still described only `@UVALIDATE` and
  "Text/Notes fields" — factually wrong since 1.0.0. It now covers the four
  composable rule kinds and the Validation scan.
- `tests/hook_php.php` 187→197.

## 1.4.0 — the Validation scan (retrospective project sweep)

The last piece of the 1.x expansion: a project page that runs EVERY configured
rule over EVERY saved record. Live validation guards the form; the scan
reaches what it cannot — Data Import Tool and API writes (save-hook coverage
is version-dependent), and records entered before a rule existed.

- **"Validation scan" project link** (`pages/scan.php`), visible to users
  with design rights via `redcap_module_link_check_display` and re-checked on
  the page. Read-only; results as an on-page table and a CSV download
  (quoted, spreadsheet-formula-defused).
- **One dispatch, two consumers.** `auditRule` was refactored into a thin
  logging wrapper over the new `ruleFindings()` — pure evaluation returning
  findings — and the scan consumes the same method, so the save-hook audit
  and the scan can never disagree about what a violation is. All 175
  pre-refactor hook checks pass unchanged.
- **Scan semantics.** Records are read in chunks (memory-safe on large
  projects); every record/event/instance context is evaluated, with repeat
  rows merged over their event row exactly as the audit's value reader does.
  Unique rules run as ONE aggregate pass over the scanned data (project /
  DAG / event scopes honored; a group is a violation only across two or more
  distinct records) instead of a whole-project read per record.
- **Privacy by construction.** The report names record / event / instance /
  field / rule / reason — never the stored value (staff open the record under
  REDCap's own access control). A DAG-bound user scans only their own group's
  records; an unresolvable DAG scans nothing rather than everything.
- **Verification.** `tests/hook_php.php` 175→187: all four modes found where
  seeded, DAG record-set confinement, dag-scoped unique across DAGs, repeat
  instance numbers, chunked reads, config-error exclusion, and a guard that
  no stored value appears in the report.

## 1.3.0 — no duplicates across records (`@UVUNIQUE`)

Fourth validation mode, and the module's first server round-trip: field-level
uniqueness, which REDCap has no native equivalent for. As the value is typed,
the browser asks the server whether it is already recorded in another record
(framework AJAX — CSRF-protected, survey-aware) and shows used/free live with
the usual message/confirm/block enforcement.

- **`@UVUNIQUE` tag / `unique` mode.** Bare (project-wide), `=project|dag|event`
  scope shorthand, or JSON `{with, scope, when, message, blockSave, surveys}`.
  `with` makes the key composite (value + those fields together unique, e.g.
  specimen ID within site); available in all three configuration channels
  (new dialog boxes: composite fields, scope, survey opt-in).
- **Privacy posture.** The endpoint re-derives scope/composite from stored
  rules — nothing security-relevant is trusted from the page — and answers
  ONLY for fields carrying a live unique rule, so it cannot be used as an
  existence oracle for arbitrary fields. Staff see the colliding record id
  only inside their own DAG; surveys are an explicit per-rule opt-in
  (`surveys:true`) answered boolean-only, never a record id.
- **Fail-open transport.** No JSMO, a network error, an error response, or an
  answer that never arrives — each leaves the field unflagged and never traps
  a save; the console explains why. A one-deep answer cache plus a pending-key
  guard means one request per candidate value (the direct listeners and the
  when-registry self-watch cannot double-fire a request), and stale responses
  are discarded by sequence.
- **The race is audited, not denied.** Two near-simultaneous saves can both
  pass the live check; the post-save audit re-checks the saved value against
  every other record (same scope/composite semantics via one shared
  `findCollision`) and logs `type: unique, reason: duplicate-value`.
- **Field types.** Text, Notes, dropdown, radio, yes/no, true/false, slider
  (no calc); composes with the other modes on one field.
- **Verification.** New `tests/unique_dom_js.cjs` (32 checks: transport stub,
  payload shape, composite re-check, fail-open paths, pending/stale/cache,
  survey opt-in, when-gate, composition); `tests/hook_php.php` 151→175 (the
  AJAX endpoint end-to-end: collision/self-exclusion/trim, anti-oracle,
  composite, survey opt-in + boolean-only, DAG masking both directions,
  payload hygiene, event-scope read, audit backstop, JSMO injection on/off,
  dialog save-gate); `tests/annotation_php.php` 121→136. Full JS + PHP
  7.4/8.3 suites green.

## 1.2.0 — Constraint and Required rules in the Configure dialog

The two 1.x modes reach the dialog and fast-entry channels, so every rule kind
is now available in all three configuration channels (dialog, fast entry,
action tags) — no annotations needed for cross-field or required rules.

- **"What this rule checks" selector.** The dialog's rule-type radio grows two
  kinds: *Constraint* (cross-field: invalid unless a condition is true) and
  *Required* (must not be blank, optionally gated by "Only validate when").
  New per-rule boxes: the constraint **condition** (`assert`) and the shared
  optional **message**.
- **Per-mode key isolation.** Constraint/Required rows read ONLY their own
  boxes — algorithm/pattern/pooled boxes visible in the shared dialog are
  ignored for those kinds (their labels say so), proven by a test that puts a
  catastrophic regex in an ignored box and shows the rule still runs clean.
- **Per-mode field types in the dialog** (mirrors the annotation channel):
  constraints accept Text/Notes/dropdown/radio/yes-no/true-false/calc/slider;
  required the same minus calc; check rules stay Text/Notes.
- **Save-time gate covers the new kinds.** A constraint without a condition, a
  function in the dialect, required-on-calc, and an assert referencing an
  unknown field are all rejected in the dialog with the row named; a check
  rule and a constraint sharing a field pass (they compose, not conflict).
- **Docs.** README channel intro reflects the four kinds; USER_GUIDE's
  outdated "no cross-field validation" and "Text/Notes only" answers updated.
- **Verification.** `tests/hook_php.php` 139→151 (dialog-channel audits for
  both modes, key isolation, save-gate cases). Full JS + PHP suites green.

## 1.1.0 — conditional required (`@UVREQUIRED`)

Third validation mode: a field must not be left blank — with the two things
REDCap's own required flag lacks, a **condition** and a **real block**. Native
required is unconditional and only warns; `@UVREQUIRED="[consent]='1'"` turns
the requirement on and off live as the referenced fields change, and
`blockSave:"hard"` actually stops the browser save.

- **`@UVREQUIRED` tag / `required` mode.** Bare (always required), a bare
  condition value as the `when` shorthand, or JSON `{when, message, blockSave}`.
- **The inverse emptiness rule.** Every other mode is inert on blank; required
  fires ON blank (whitespace-only counts). Filling the field clears the notice
  — deliberately no green "OK", because required never judges the value. Pair
  with `@UVALIDATE`/`@UVASSERT` (modes compose): on a blank field only required
  fires; on a filled-but-wrong value only the value checks fire.
- **Field types.** Text, Notes, dropdown, radio, yes/no, true/false, slider —
  not calc (the person entering data cannot fill a calc, so requiring one would
  trap them). Read-only fields show the notice but never block (UX-003).
- **Self-watch via the shared when-registry.** The factory watches its own
  field through a synthetic `[field]<>''` gate, reusing the existing
  `___radio`/select/hidden-mirror listener wiring instead of duplicating it.
- **Server audit.** A blank-while-required save logs as `type: required`,
  `reason: required-blank`; a blank carries nothing identifying, so the entry
  is safe in every privacy mode. The `when` gate is honored server-side.
- **Verification.** New `tests/required_dom_js.cjs` (33 checks: blank/fill,
  whitespace, live when-flip, dropdown + radio anchors, composition, readonly
  exemption, branch conflict); `annotation_php` 109→121, `hook_php` 128→139.
  CI wired.

## 1.0.0 — cross-field constraints (`@UVASSERT`)

The 1.0 milestone: the module grows beyond ID/check-character validation into a
universal data-integrity validator. This release adds a second validation mode,
`@UVASSERT`, that turns the existing condition engine from an applicability
*gate* into a validation *test*: the field is invalid unless a cross-field
condition holds. This closes a gap stock REDCap leaves open — branching only
hides, a range check only warns, and Data Quality runs in batch — none can
*block* a bad relationship at entry.

- **`@UVASSERT` tag / `constraint` mode.** `@UVASSERT="[end_date]>=[start_date]"`,
  or JSON `{assert, message, blockSave, when}`. The condition uses the same
  REDCap-style dialect as `when` (parity-locked in `php/Logic.php` + the JS
  twin); ISO dates and numbers compare correctly. Confirm-a-value is just
  `@UVASSERT="[id]=[id_confirm]"`.
- **An empty field is inert** (requiring a value will be `@UVREQUIRED`'s job).
  `message` is the designer's own wording, with a generic fallback.
- **Field types.** Constraints attach to Text, Notes, dropdown, radio, yes/no,
  true/false, calc and slider fields (check-character/regex validation stays
  Text/Notes). The validated field's value is read through the same
  name-convention reader the `when` gate uses.
- **Modes compose.** A field may carry `@UVASSERT` alongside `@UVALIDATE`; the
  two attach independent validators and both must pass. Duplicate detection and
  `Branching::resolve()` now key on **(field, mode)** — same-mode sharing still
  branches; different modes coexist. Each validator owns an independent
  save-block item, so a passing constraint never clears a failing check's block.
- **Server audit + privacy.** `redcap_save_record` evaluates the assert against
  saved values and logs a failure as `type: constraint`. Off-instrument refs are
  server-`fold()`ed to constants, so no record value reaches the page (SEC-005).
- **Verification.** New `tests/constraint_dom_js.cjs` (assert test, dropdown,
  when-gate, composition, branches, config error); `tests/annotation_php.php`
  and `tests/hook_php.php` extended for the parser and the server audit. Full
  JS + PHP (7.4/8.1/8.3) suites pass.

## 0.9.1 — conditions are resolved on the server; no record value reaches the page

Security fix (SEC-005) for a data-exposure regression introduced with the
`when` feature in 0.8.0, found while reviewing the 15 Jul 2026 external-module
security scan. **Sites running 0.8.0 or 0.9.0 should take this release**;
0.7.1 and earlier are unaffected (they never sent record data to the page).

- **What was wrong.** To let the browser evaluate a condition that references a
  field on another instrument, 0.8.0 baked that field's saved value into the
  page as a `whenValues` block. Anything in the page is readable by whoever
  loads it — so on a **survey page** a respondent could read a staff-only field
  out of the page source (`View Source` → `inspire-validator-config`), and on a
  data-entry form a user without rights to that instrument could do the same.
  Only fields named in a `when` condition were exposed, and only for the record
  being viewed; no XSS was involved (see below).
- **The fix.** A field the browser cannot see also cannot change while the page
  is open, so there is no reason to send its value. The server now resolves
  those comparisons itself and sends only the outcome: `Logic::fold()` walks
  each condition and replaces every comparison it will not ship a value for
  with a `["const",true|false]` node. The page now carries field names, the
  designer's own literals, and booleans — never a record value. Comparisons
  over fields of the rendered instrument are untouched and still react live as
  the user edits them; a comparison mixing an on- and off-instrument field is
  folded whole (correct at page load, no live reaction — documented).
  `whenValues` is gone from the injected config.
- **The two scanner findings (`TaintedHtml`, `TaintedTextWithQuotes`) were a
  correct false positive** for XSS — verified by pushing `</script><script>`,
  `"><img src=x onerror=>` and three more breakout payloads through the old
  path: `json_encode`'s `JSON_HEX_TAG|AMP|APOS|QUOT` flags escaped every markup
  character, exactly as the scan summary argued. That analysis stands. What the
  findings were pointing at, though, was the taint flow itself — saved record
  values reaching the page — and this release removes it at the source. The
  `REDCap::getData()` → `echo` string flow that appeared in 0.8.0 and made the
  two builds differ from the clean v0.7.1 scans no longer exists, so the
  whitelist request to the framework maintainers should no longer be needed —
  worth a scanner re-run to confirm.
- **Tests.** `tests/hook_php.php` now asserts the absence of record values in
  the emitted page on both form and survey contexts, and the folded shape of
  every rule/branch condition; `tests/when_php.php` unit-tests `fold()`
  (live refs kept, off-page folded, mixed comparisons folded whole, checkbox
  refs, values absent from the output); the shared `tests/when_fixture.json`
  gains `astEval`/`astRefs` sections that lock the `["const",bool]` wire format
  across both runtimes; `tests/when_dom_js.cjs` covers pre-folded ASTs, the
  AST-beats-text precedence, and the parse-the-text fallback.

## 0.9.0 — branched validation, rename, opt-in hints, chip colors

Four changes from live use. The headline: one field may now be covered by
SEVERAL rules, each gated by a `when` condition — "validate as Verhoeff when
[specimen_type]='2', otherwise as a plain format code".

- **Branched validation.** Sharing a field is legal when every sharing rule
  carries a `when`, plus at most ONE rule without (the else branch, firing
  only when no condition is true). The new pure `php/Branching.php` resolves
  sharing at config-build time into explicit per-field branch rules that the
  client engine, the server audit, and the saved-value snapshot all consume —
  its docblock is the normative semantics spec. Runtime: the branch whose
  condition is true validates; none + no else = the field is inert; MORE than
  one true = a "Validation conflict" notice naming both conditions, nothing
  validated, the save never blocked, and an `uvalidate-unconfigurable` entry
  (never silent). Illegal sharing (two unconditional rules, byte-identical
  conditions, single+pooled mix) is a configuration error — in the Configure
  dialog it is rejected at save time naming the rows. Both engine factories
  now build their whole mode-resolution closure per VARIANT (an internal
  `makeVariant` seam; a plain rule is exactly one variant, so single-rule
  configs take byte-identical code paths). `blockSave` and `suggestFix` are
  per branch; the submit guard now skips items whose ACTIVE mode is "off".
  One field annotation may carry several `@UVALIDATE` tags
  (`extractTags`/`parseFieldAll`/`groupMulti`; the single-tag forms delegate,
  so nothing existing changed), and dialog + annotation rules may legally
  share a field cross-channel. New `tests/branching_php.php` and
  `tests/branch_dom_js.cjs` implement the same scenario table on both sides;
  `tests/hook_php.php` drives branch selection through the full audit.
- **Renamed** to **"Universal Regex & Check-Character Validator — IDs, codes &
  patterns"** (module list, Configure dialog, action-tag help, page notices,
  docs). The PHP namespace, the `INSPIREUniversalValidator` JS global, and the
  `inspire-validator-config` node are deliberately unchanged — nothing
  installed breaks.
- **Check-character hints are now OFF by default and configurable.** The
  "should end in X" tail on a check mismatch (`suggestFix`) revealed the
  expected check character, which can entice staff to force-fit a mistyped ID
  instead of re-scanning it. It previously had NO off switch; now it is a
  per-rule opt-in in all three channels (dialog checkbox, `"suggestFix":true`
  JSON key — strict boolean), default off. The progressive "what's still
  missing" format guidance is unchanged (it reveals the shape, never the
  answer).
- **Pooled chip severity colors corrected.** What read as "errors are yellow"
  was leftover-junk chips (amber) — actual invalid members were always red,
  and DUPLICATES shared that same red. Now hard problems are red (invalid ✗
  AND junk ?) and a repeat-scan of a VALID ID is amber ⊗ "(again!)" — a
  warning, not an error. Both color pairs were already WCAG 2.2 AA
  (amber 5.7:1, red 4.9:1) and every state keeps its non-color mark. New
  `tests/pooled_dom_js.cjs` locks the severity mapping.

## 0.8.0 — conditional validation: the `when` rule key

## 0.8.0 — conditional validation: the `when` rule key

A rule can now carry an optional REDCap-style condition and validates only
while it is true — `@UVALIDATE={"algorithm":"verhoeff","when":"[specimen_type]='2'"}`,
or the new "Only validate when" box on each Configure-dialog rule. A false
condition makes the rule inert in the browser (message cleared, a *Compulsory*
block never traps the save) and skips it in the server audit; it does NOT erase
the value (unlike REDCap field branching).

- **New `php/Logic.php` — the normative dialect spec.** A deliberate subset of
  REDCap logic: `[field]` and `[checkbox(code)]` references, quoted/number
  literals, `= <> != > < >= <=`, `and/or/not`, parentheses. Functions
  (datediff…), smart variables, `[event][field]` prefixes, arithmetic and
  piping are rejected when the rule is saved — with an error naming the
  construct — instead of misbehaving later. Caps: 500 chars, 20 refs, 10
  nesting levels. Comparisons are numeric iff both resolved sides are numeric
  (own regex — no `is_numeric`/`Number()` leniency where PHP and JS disagree),
  else exact case-sensitive string comparison; missing/empty refs read `''`,
  checkbox refs read `'1'`/`'0'`.
- **Cross-runtime parity, same discipline as everything else.** A JS twin
  (`QRID_when*`, exposed as `whenLogic` on the namespace) lives in the
  module-authored layer of `js/engine.js`; the hand-curated
  `tests/when_fixture.json` locks parse errors, verdicts, referenced-field
  extraction and the caps across both runtimes via `tests/when_js.cjs` +
  `tests/when_php.php` (both in CI, which also lints and ships the new PHP
  file).
- **Live browser gate.** Referenced fields on the page react as they are
  edited (dropdowns, radio groups via REDCap's hidden input, checkbox options
  by `__chk__` name), shared listeners re-check every gated field on a flip,
  and refs to fields on other instruments resolve from a saved-value snapshot
  (`whenValues`) baked into the page config — checkbox maps are cast to JSON
  objects so sequential codes (0,1,2…) cannot degrade into arrays. A condition
  that cannot be evaluated fails OPEN (validation skipped, reason on the
  console) so a save is never trapped by a gate bug; `tests/when_dom_js.cjs`
  drives all of it through the DOM stub.
- **Server audit honors the condition.** The one-getData read now includes
  condition refs (never instrument-filtered), checkbox arrays survive
  `readValues` (`$keepArrays`), and `auditRule` skips a false-condition rule
  entirely — with the unconfigurable-log fallback if the stored condition can
  ever not be parsed. Page hooks thread record/event/instance context into the
  config build for the snapshot.
- **All three channels, one validator.** `when` joins `@UVALIDATE`'s JSON keys,
  the dialog (`rowsFromFlatSettings`/`settingRowToRule`), and fast entry;
  `checkFragment` validates the syntax for every channel, and the
  dictionary-dependent checks (field exists; checkbox needs a real `(code)`;
  no file/descriptive refs) run wherever the data dictionary is available —
  including the save-time `validateSettings` gate. Identical tags still group;
  tags differing only in `when` split into separate rules.
- **Docs.** README "Conditional validation" section (dialect table, live vs
  snapshot, the no-erasure difference from field branching, calc-ref liveness
  caveat), INSTALL.md, a manual `when` checklist in TESTING.md, and the
  js/README.md deviation list.

## 0.7.1 — post-review corrections for the weighted-modulus family

## 0.7.1 — post-review corrections for the weighted-modulus family

Closes every finding from the 2026-07-13 multi-agent adversarial review of
0.7.0 (7 dimensions, each finding independently refutation-tested by executing
all four runtimes). No engine math changed; the review confirmed 0 blockers.

- **Docs — `weighted_mod11` detection claim scoped honestly.** The linear
  ISBN-10 weighting puts weight 11 ≡ 0 (mod 11) on the 10th-from-right digit,
  so a substitution there is invisible for payloads of 10+ digits. The blanket
  "catches every single-digit error" claims in the README, the engine module
  docstring, and the 0.7.0 entry's "detection-equivalent to `iso7064_mod11_2`"
  wording are all corrected to state the ≤9-digit boundary; the
  `WeightedModulus` docstring now documents the blind positions explicitly.
- **Dropdown help states the domain.** The `weighted_mod11` choice now says
  "full strength only up to 9 digits — prefer Mod 11,2 for longer IDs" and the
  `mrz_mod10` choice says "digits ONLY … not for letter-bearing MRZ fields",
  closing the two label-clarity findings.
- **Fixture now locks the weighted validate/append path.** The cross-runtime
  contract gains all four weighted schemes through their real `digits_only`
  config plus a dedicated `weighted_mod11` `X`-check-tail group (valid mint,
  revalidate, and a hand-tampered `TBX-00007X` that must fail), so the
  peel-check-then-extract-source order can no longer drift in any single
  runtime unnoticed. 918 rows total (332 scheme_ops, up from 219); the same
  rows now also run under Excel/VBA (879 assertions) and the playground
  self-test (1592 checks), whose embedded fixture copy was refreshed.
- **New `tests/explain_js.cjs` (wired into CI).** Asserts
  `explain(payload).check === compute(payload)` for every fixture row, porting
  the playground's explain-vs-compute guard so the vendored derivation tracer
  cannot silently drift from the verified engine.
- **`js/engine.js` header catalog completed.** The four weighted schemes are
  documented in the in-file algorithm reference (they were present in the
  engine and the upstream snippet catalog but missing from this file's
  header), each with the "digit-only — use source digits_only for
  letter-bearing IDs" note.
- Studio (sibling repo): the four new preset example strings are recomputed
  over the same `00239` base every other preset uses, matching what the
  generator actually mints.

## 0.7.0 — four widely-used weighted-modulus check schemes

Adds four digit-only check-character methods so the validator can mint and verify
IDs that use the check schemes already embedded in common external identifiers:

- **`gs1_mod10`** — GS1 Mod-10 (weights 3,1 from the right): GTIN/EAN/UPC, GLN, SSCC.
- **`aba_mod10`** — US ABA routing-number Mod-10 (weights 3,7,1 from the left).
- **`mrz_mod10`** — ICAO 9303 machine-readable-zone Mod-10 (weights 7,3,1, no complement).
- **`weighted_mod11`** — ISBN-10 weighted Mod-11 (may emit `X`).

The three Mod-10 schemes catch every single-digit error at any length but miss
adjacent swaps of digits differing by 5. `weighted_mod11` matches
`iso7064_mod11_2`'s detection only up to 9 digits, the ISBN-10 domain — at 10+
digits the position carrying weight 11 goes blind to substitutions (prefer Mod
11,2 or Mod 97,10 for longer numbers, which stay strong at any length). It is
provided for compatibility with externally-minted ISBN-style IDs, not for extra
strength. *(Wording corrected in 0.7.1: this entry originally called it
"detection-equivalent", which holds only for payloads up to 9 digits.)*

- One data-driven engine: a single `WeightedModulus` primitive parameterised by a
  `WEIGHTED_SCHEMES` table (weights, modulus, direction, complement, alphabet) in
  each runtime, so a future scheme is one table row, not new code. Kept in step
  with the Python source of truth (`qrcode_generation/check_characters.py`) and the
  JS/VBA ports through the shared `check_fixture.json` (now 574 compute rows across
  15 algorithms; 805 rows total).
- Selectable from the settings dropdown and via `@UVALIDATE` shorthands (`gs1`,
  `gtin`, `ean`, `upc`, `aba`, `routing`, `mrz`, `icao`, `isbn`, `mod11w`).
- New completeness guard: `tests/algorithm_coverage_js.cjs` and
  `tests/algorithm_coverage_php.php` assert the algorithm set is identical across
  the fixture, the JS registry, the PHP engine, `AnnotationRules::ALGORITHMS`, and
  the `config.json` dropdown, so a half-wired algorithm (added to some surfaces but
  not others) fails CI. Both are wired into `.github/workflows/parity.yml`.

## 0.6.0 — algorithm-name shorthands (ease of use)

Configuring a rule no longer requires typing the full internal algorithm name.
The `algorithm` value now accepts case-insensitive shorthands in both
configuration channels — `@UVALIDATE=3736` (or `37,36`, `mod37_36`) resolves to
`iso7064_mod37_36`, `9710` to `iso7064_mod97_10`, `112` to `iso7064_mod11_2`,
`mod10` to `luhn`, `regex`/`format` to `none`, and so on for each method.

- Single source of truth: `AnnotationRules::ALGORITHM_SYNONYMS` (canonical name →
  list of shorthands) plus `AnnotationRules::canonicalAlgorithm()`. Shorthands are
  resolved server-side wherever a raw algorithm string enters a rule (the
  `@UVALIDATE` bare and JSON forms, and the settings dialog), so the check-character
  engine, the server-side audit, and the browser all receive the canonical name —
  no second synonym table in the JavaScript engine to keep in sync.
- Unknown values are still rejected with the existing "unknown check algorithm"
  error, and full names keep working everywhere. Documented in the `config.json`
  action-tag help, the README (with a shorthand table), and `docs/INSTALL.md`.
- Tests: `tests/annotation_php.php` gains shorthand-resolution, case-insensitivity,
  and a maintenance guard that fails if a shorthand ever collides with a canonical
  name or another shorthand; `tests/hook_php.php` proves a shorthand annotation is
  audited server-side under its canonical name.

## 0.5.1 — polynomial-ReDoS gate closure

Addresses `reports/predeployment-adversarial-review-2026-07-12b.md`, the second
independent pass, which re-verified 21 of 22 code findings from `0.5.0` as
genuinely fixed and re-opened one: **SEC-001R**.

Security
- **SEC-001R:** the regex safety gate now rejects the *polynomial* backtracking
  class it previously let through — two or more unbounded quantifiers (`*`, `+`,
  `{n,}`) over overlapping character classes with no mandatory separator between
  them (`.*.*`, `[0-9]*[0-9]*`, `[A-Z]+[A-Z0-9]+`, `A*A*A*9`, and repeated
  ungrouped/collapsed atoms such as `(abc)+(abc)+`). The `0.5.0` gate caught only
  the exponential shapes; the polynomial ones passed every configuration channel
  (settings dialog and `@UVALIDATE`) and, because a browser's backtracking engine
  has no runtime backstop, froze the tab on ordinary form and survey input —
  measured at ~20 s for `.*.*.*.*.*b` at a 200-character value, and unbounded at
  the 512-character field cap. PCRE2 auto-possessifies the same patterns and was
  never affected, which is why the client-only exposure was missed. The new
  second stage (`QRID_polyOverlap` / `CheckCharacter::polynomialOverlap`)
  tokenizes the already-group-collapsed pattern and refuses the
  overlapping-unbounded shape while still admitting genuinely-linear patterns —
  disjoint adjacent classes (`[A-Z]+[0-9]+`) and a mandatory separator (`.*x.*`).
  A flagged pattern is never compiled on the client, so it can never run. The JS
  and PHP twins stay behavior-identical, locked by an expanded
  `tests/risky_patterns.json` that now covers the polynomial class in both
  runtimes. Chosen over a bundled linear-time client engine (RE2/`re2js`) to keep
  the module a build-free, dependency-free vendored script.

Tests and docs
- `tests/risky_patterns.json` gains the polynomial-overlap cases in `risky` and
  two linear precision-guarantee cases (`[A-Z]+-[A-Z]+`, `.*x.*`) in `safe`.
- The residual class the gate deliberately still passes is now a *bounded*
  backtracker (`A{1,40}A{1,40}A{1,40}9`, work capped by the pattern rather than
  the input length); `risky_php.php` and `hook_php.php` use it — instead of the
  now-gated `A*A*A*9` — to keep exercising the server's match-time PCRE-error
  guard.
- Config-error messages (dialog + `@UVALIDATE`), `config.json`, `docs/INSTALL.md`,
  `docs/TESTING.md`, `js/README.md`, and `tests/README.md` now describe both gate
  stages and no longer imply the input caps bound regex match time (LOW-02).
- **LOW-03:** the JS fallback config `strip` default (`-/ _|`) now matches the
  PHP default and the `config.json` help text (`-/ _|\`). Production always
  receives the PHP-built config, so this only affected the test-harness fallback.

The three standing release-gate blockers (public SemVer tag, REDCap security
scan, live REDCap/browser/screen-reader matrix) remain people-work, tracked at
the top of `docs/TESTING.md`. LOW-04 (cross-save log deduplication) remains
disclosed future work.
## 0.5.0 — predeployment-review hardening

Addresses `reports/predeployment-adversarial-review-2026-07-12.md` (4 blockers,
7 high, 11 medium, 4 low). Every code-addressable finding is fixed here; the remaining
release-gate items (public SemVer release, REDCap security scan, live
REDCap/browser/screen-reader matrix) are people-work, tracked as explicit
blockers at the top of `docs/TESTING.md`.

Security and privacy
- **SEC-001:** the ReDoS gate now catches repeated alternation/optional groups
  (`(a|aa)+`, `(a?)+`, `((a)|(aa))+`, non-capturing/lookahead variants) by
  collapsing inner groups layer by layer, extends the adjacent-quantifier rule
  to `*{`/`+{`, and caps the pattern source at 512 code points. Both runtimes
  stay byte-identical (14,399-pattern differential: zero divergence). The
  pre-fix gate passed `(a|aa)+`, which froze a browser tab on 43 characters.
- **SEC-002:** every settings read on the audit path now carries the hook's
  explicit `$project_id` (`getSubSettings('rules', $pid)`,
  `getProjectSetting('log-values', $pid)`); the hook-test mocks now REFUSE to
  return settings without a resolvable pid, so a regression fails the suite.
- **SEC-003:** the audit-error path honors the project's log-privacy mode:
  keyed record hash in `none`, no record identifier at all in `off`, and the
  exception MESSAGE (which can quote data) only with the new `debug-log`
  project setting on. Previously a raw record id leaked in every mode.
- **SEC-004:** logged identifiers are keyed, project-scoped HMAC-SHA-256
  (module-held secret in a system setting) instead of plain SHA-256 — no
  offline enumeration, no cross-project linking; settings copy now says
  pseudonymization, not anonymity. Log keys renamed `value_hmac`/`record_hmac`.
- **SEC-005 (partial):** instrument scoping (below) removes the main duplicate
  source — unrelated-instrument saves re-logging an old invalid value. Full
  cross-save deduplication remains future work.

Correctness
- **COR-001:** with an event id supplied, the audit reads ONLY that event's
  node — the whole-record fallback that could validate (and log) another
  event's value now runs only when the hook supplies no event id at all.
- **COR-002:** `validateSettings()` rejects invalid rules at save time with a
  message naming the rule; one shared validator (`AnnotationRules::checkFragment`)
  serves the dialog, annotations, and the save gate; each rule is audited in
  isolation (one failure cannot abort the rest); rules the server cannot
  evaluate log `uvalidate-unconfigurable` instead of passing silently.
- **COR-003:** non-Text/Notes fields are rejected by the dialog channel too
  (config error naming the field and its type), matching the annotation channel.
- **COR-004:** the client/server regex parity subset is now explicit: patterns,
  strip, and keepChars must be printable ASCII (enforced at save time), and the
  server format audit fails open on non-ASCII values instead of risking a
  verdict the browser never showed. Python-only `(?P<...)` is rejected with
  `\A`/`\Z`; patterns must compile in PCRE at save time.
- **COR-005:** field-keyed registries use prototype-free objects, so a field
  named `constructor` can no longer corrupt duplicate detection.
- The single-field factory no longer lets an absent `source`/`strip` override
  scheme defaults with `undefined` (crashed on minimal configs).

Performance
- **PER-001:** the audit is scoped to the saved instrument (conservatively
  auditing everything when the instrument or dictionary is unknown, e.g. some
  import/API contexts); fields claimed by two rules are skipped exactly like
  the client; an unrelated-instrument save now reads no data at all.
- **PER-002:** hard rule caps (ID length ≤ 64, ≤ 32 candidate lengths,
  keepChars ≤ 64, expectedIds ≤ 9999) plus a per-rule pooled work budget that
  shrinks the scan cap for expensive configs (identical formula in both
  runtimes; over-cap input gets "too long to scan", never a slow parse), and
  keystroke validation is debounced (150 ms; change/blur immediate). The
  review's 100–199-length config (9.25 s per parse) is now a save-time
  rejection; the worst still-legal config parses in ~100 ms at its cap.
  Huge min/max values no longer allocate a giant candidate array (browser or
  server); the pooled input cap dropped 8192 → 4096.

Accessibility and UX
- **A11Y-001:** messages are polite live regions (`role=status`,
  `aria-live=polite`, stable ids) wired via `aria-describedby`; inputs carry
  `aria-invalid`; block dialogs name fields by their visible label and focus
  the field. New `tests/a11y_dom_js.cjs` locks the DOM contract; live
  screen-reader verification added to `docs/TESTING.md`.
- **A11Y-002:** pooled junk-chip text darkened `#b26a00` → `#8a5500` on
  `#fbf6e8` (3.93:1 → 5.75:1, WCAG 2.2 AA for normal text).
- **UX-001:** configuration errors attach to their field when it is on the
  page, fall back to one page-level notice otherwise, and surveys show a
  generic "checking unavailable" line instead of technical detail (staff still
  see everything on data-entry forms, in the dialog gate, and in the log).
- **UX-002:** presence checks replace `empty()` (a pattern of `"0"` counts),
  the pattern box states the uppercase normalization and the ASCII subset, and
  save-time validation means designers see errors in the dialog, not typists.
- **UX-003:** enforcement copy now says BROWSER form/survey save everywhere;
  read-only/disabled fields never arm the save blocker (no more traps on
  `@READONLY` fields); the advisory dialog no longer double-prompts when a
  save-button click is followed by its own submit.
- **CMP-001 (partial):** everything below the vendored core now lives in one
  IIFE publishing exactly one global (`window.INSPIREUniversalValidator`); the
  legacy `QRCheck`/`QRID*`/`__QRIDGuard` globals are gone, and the config
  travels only through the inert JSON node (no config global, no inline config
  script). JSMO/`tt()` translation integration remains future work and is
  disclosed as a known limitation (English-only messages).

Claims, docs, assurance
- **PRE-004/DOC-001:** the Repo-facing description, class header, README,
  INSTALL, and settings copy no longer claim API/import audit coverage — they
  describe a best-effort post-save audit wherever REDCap fires the hook, with
  the live-instance verification step linked; "the server always logs" removed
  (off mode exists); stale test counts replaced by the actual suite list.
- **PRE-001/002/003:** recorded as explicit release-gate blockers in
  `docs/TESTING.md` (public SemVer release + anonymous download, current REDCap
  security scan, executed live matrix incl. browsers and a screen reader).
- **TST-001:** hook suite grown 14 → 56 checks (strict-pid mocks, privacy modes
  on success and exception paths, event/instrument scoping, repeats,
  duplicates, isolation, HMAC, `validateSettings`); annotation suite 30 → 52
  (shared-validator coverage); risky list 22 → 34 patterns + length-cap and
  measured-time checks; new 19-check DOM/a11y suite; PHP matrix 7.4/8.1/8.3 in
  CI so the declared floor is tested.
- **CI-001/PKG-001:** workflow token limited to `contents: read`; actions
  pinned to commit SHAs; new `package` job builds `universal_validator_vX.Y.Z.zip`
  from `git archive` and verifies required files, excluded dev trees
  (`.github`, `reports` via `export-ignore`), JSON validity, and namespace
  match.

## 0.4.0 — bulk configuration + repositioning

Driven by first live-REDCap field testing: configuring many fields one picker
click at a time doesn't scale, and the module under-sold what it validates.

- **`@UVALIDATE` field annotations** — a second configuration channel. Tag fields
  in the Online Designer's Action Tags box or the `field_annotation` column of
  the data dictionary CSV (bulk setup = one spreadsheet column + one upload).
  Forms: bare tag (default check), `@UVALIDATE=<algorithm>`, or full-rule JSON
  (`type`, `algorithm`, `source`, `pattern`, `strip`, `keepChars`, `idLengths`,
  `idMinLen`, `idMaxLen`, `expectedIds`, `blockSave`, `note`). Malformed tags,
  unknown keys, catastrophic patterns, and tags on non-text fields all surface as
  per-field configuration errors, never silent no-ops. Identically-tagged fields
  are grouped into one rule. Parsing lives in the new `php/AnnotationRules.php`
  (pure, no REDCap dependency) with 30 unit checks in `tests/annotation_php.php`,
  wired into CI.
- **Fast entry** — each dialog rule gets a text box for comma/space-separated
  field names, merged with the field pickers. Names are checked against the data
  dictionary; misspellings show a configuration error naming the bad field.
- **Rule labels** — an optional per-rule note so a project with many rules stays
  readable ("Specimen IDs", "Legacy screening codes").
- **Repositioning** — renamed to *Universal Field Validator — IDs, codes &
  patterns*: check-character IDs remain the flagship, and the regex side (any
  structured value, no administrator-added validation types needed) is now
  first-class in the name, description, README, and dialog labels.
- Configure-dialog copy rewritten around "one rule, many fields" (the field
  picker's + button, fast entry, annotations).

## 0.3.0 — REDCap-standards conformance

Addresses `reports/final-adversarial-review-2026-07-07.md` (no runtime bugs; five
conformance/maintainability findings, all closed).

- **Framework:** `framework-version` 9 → 14 (the version REDCap 13.7.0, our
  declared floor, supports) and the pre-framework-12 hook `permissions` block is
  removed, per the official docs.
- **Settings UI:** `branchingLogic` removed from the repeatable `sub_settings`
  (the official docs warn of known issues with that combination); the pooled-only
  settings are labeled "Pooled only:" instead.
- **Privacy:** the `none` log mode is now a true minimal-identifier mode — it
  hashes the record ID as well as omitting the value (record IDs can themselves be
  identifying at some sites). `hashed`/`raw` keep the raw record ID so staff can
  fix the record; setting text and docs now state exactly what each mode stores.
- **JavaScript:** one public namespace, `window.INSPIREUniversalValidator`
  (config, engine, factories, validators, guard), per REDCap JS guidance; the
  individual upstream globals remain as deprecated aliases for the
  JavaScript-Injector contract.
- **Docs:** the stale `engine.js` provenance header now points to `js/README.md`'s
  authoritative deviation list (a future re-vendor must not silently drop the
  hardening); README/tests README count the current six-test CI matrix; new
  `docs/TESTING.md` manual REDCap integration checklist (classic, longitudinal,
  repeating, survey, API/import, log modes, security spot-checks).

## 0.2.0 — adversarial-review hardening

Addresses the findings in `reports/adversarial-review-2026-07-07.md`.

Security
- **UV-001:** the client config is embedded as inert JSON in a
  `<script type="application/json">` block and parsed with `JSON.parse`, hex-escaped
  (`JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT`, no `JSON_UNESCAPED_SLASHES`). A project
  setting can no longer break out of the inline script (stored XSS).
- **UV-002:** all config-derived text (regex class bodies, config-error messages)
  is HTML-escaped before it reaches `innerHTML` in the client (DOM XSS).
- **UV-006:** `idPattern` is rejected at config time if it has nested/adjacent
  unbounded quantifiers, and per-field input-length caps bound the work (ReDoS).

Server-side coverage
- **UV-003:** `redcap_save_record` now mirrors the full client rule set — single
  and pooled fields, check character, format pattern, and regex-only.
- **UV-004:** server reads the exact saved event and repeat instance instead of the
  first matching value (fixes longitudinal / repeating-instrument audits).
- **UV-005:** invalid-ID logging no longer stores raw identifiers by default; the
  new **log-values** setting chooses hash (default) / none / raw / off.
- **UV-007:** server reads all fields in one `getData` call; the client
  `MutationObserver` is disconnected when a field never appears.

Configuration & tests
- **UV-008:** exposes exact ID length(s), min/max, and keep-chars for pooled rules;
  `expected-count` and lengths are strictly validated (a bad value is a visible
  config error, not silent coercion). Adds a `compatibility` block.
- **UV-009:** PHP normalization is multibyte-safe (`mb_strtoupper`, code-point
  splitting, `\p{Nd}` source extraction) so client and server agree on Unicode.
- **UV-010:** parity tests now cover `normalize` and `scheme_ops` (643 rows), a new
  `pooled_fixture.json` freezes the pooled parser across both runtimes, and CI adds
  `php -l` and a fixture-staleness check.

Follow-up (fix-validation review, `reports/fix-validation-review-2026-07-07.md`)
- **P2:** the server now mirrors the browser's catastrophic-pattern gate.
  `CheckCharacter::riskyPattern()` (the byte-identical PHP twin of
  `QRID_riskyPattern`) is checked in `getRules()`, so a risky pattern is a config
  error on both sides; `matchesPattern()` and the pooled `patTest()` treat a PCRE
  engine failure (backtrack/recursion limit) as "not a real (non-)match" — the
  pooled path bails to *unconfigurable* rather than logging a false invalid-ID;
  and `pooledState()` rejects risky patterns. An adversarial review of that fix
  then found two gaps, also closed here: the heuristic now catches **bounded**
  nested quantifiers (`([0-9]{1,20}){1,20}`), not only `+`/`*`, and both runtimes
  use an explicit ASCII whitespace class instead of `\s` (JS `\s` matches Unicode
  whitespace, PCRE `\s` does not — a silent parity gap). New `risky_js.cjs` /
  `risky_php.php` lock the two heuristics to one shared list; a differential over
  thousands of generated patterns (including Unicode whitespace and bounded
  quantifiers) shows zero divergence.
- **P3:** added `.gitattributes` (`* text=auto eol=lf`) so checkouts stop showing
  phantom CRLF churn.

## 0.1.0 — scaffold

Initial standalone REDCap external module extracted from the JavaScript-Injector
script.

- Config-driven client validation on data-entry forms and surveys (no code
  pasting, no JavaScript Injector dependency).
- Repeatable per-rule settings: field type (single/pooled), fields, method,
  payload source, format pattern, separators, expected pool size, and per-rule
  enforcement (informational / advisory / compulsory).
- `js/engine.js` vendored from the `qrcode_generation` combined validator, config
  now injected by the module.
- `php/CheckCharacter.php`: PHP port of all 11 check-character algorithms for the
  server-side `redcap_save_record` guard.
- Parity harness: `tests/parity_js.cjs` (JS, 420/420 green) and
  `tests/parity_php.php` (PHP) against the shared Python-generated fixture; CI in
  `.github/workflows/parity.yml`.
