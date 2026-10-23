<?php
/**
 * The live lookups: the framework AJAX endpoint (redcap_module_ajax) with
 * unique-check and exists-check, the throttles that ration them, and the
 * rule each lookup is judged by (activeRuleFor). Moved out of
 * UniversalValidator.php unchanged (QUA-2); the class keeps the THROTTLE_*
 * constants, since a PHP 7.4 trait cannot hold one.
 */

namespace INSPIRE\UniversalValidator;

trait LiveLookups
{
    /**
     * Live uniqueness endpoint (framework AJAX). The client sends the field
     * name and the CANDIDATE values (the field's own, plus the composite
     * "with" fields'); everything else — scope, composite key, eligibility —
     * is re-derived from the module's own stored rules, never trusted from
     * the page. Anti-oracle: only a field covered by a live unique rule is
     * answered at all, so this cannot be used to probe arbitrary fields for
     * value existence.
     *
     * Survey requests (no-auth) are answered ONLY when the rule opted in
     * ("surveys": true) and always with a boolean — never a record id. For
     * authenticated staff the colliding record id is included only when it
     * is inside the user's Data Access Group (or the user has none).
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // THE DURABLE SCAN'S FOUR VERBS. Declared in auth-ajax-actions ONLY - a
        // scan reads and stores record values, so there is no version of it an
        // unauthenticated caller may reach. $user_id is the only value here that
        // means REDCap authenticated this caller; the framework's own action
        // list is a first gate, and this is the second, because
        // redcap_module_ajax() guards the action NAME and hands the identity
        // straight through without checking it.
        if (in_array($action, ['scan-start', 'scan-work', 'scan-status', 'scan-cancel'], true)) {
            if ($user_id === null || $user_id === '') {
                return ['ok' => false, 'why' => 'you are not signed in'];
            }
            if (!$project_id) {
                return ['ok' => false, 'why' => 'this action only works inside a project'];
            }
            return $this->scanAction($action, $project_id, $payload);
        }

        if ($action === 'exists-check') {
            return $this->existsCheck($payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                $survey_hash, $user_id, $group_id);
        }
        if ($action !== 'unique-check') return ['error' => 'unknown action'];
        try {
            // AUTHENTICATION, not survey-ness, decides which guards apply.
            //
            // "unique-check" is declared in no-auth-ajax-actions, so this route
            // is reachable with NO session and NO survey hash. v1.4.1 keyed its
            // guards on $survey_hash, which meant an unauthenticated caller who
            // simply OMITTED the hash was treated as staff: the surveys opt-in
            // check, the Identifier refusal and the rate limit were all skipped
            // and the endpoint still answered used/free — an unthrottled
            // existence oracle on exactly the identifying fields v1.4.1 set out
            // to protect. Defeated by leaving a parameter out. (Adversarial
            // review of v1.4.1; the tests only covered (hash,null) and
            // (null,staff) — never (null,null).)
            //
            // $user_id is the only value here that means "REDCap authenticated
            // this caller"; a survey hash is caller-supplied and proves nothing.
            $isAuthenticated = ($user_id !== null && $user_id !== '');
            $isSurvey = ($survey_hash !== null && $survey_hash !== '');
            // One wording for every refusal of a caller REDCap did not sign
            // in: "not a checkable field" for a field with no rule beside "not
            // enabled on surveys" for one with a rule listed the project's
            // unique rules to anyone with the survey link (UNQ-9).
            $refused = $isAuthenticated ? 'not a checkable field' : 'not enabled on surveys';
            $field = (isset($payload['field']) && is_string($payload['field']))
                ? strtolower(trim($payload['field'])) : '';
            if ($field === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
                return ['error' => $refused];
            }
            $raw = (isset($payload['values']) && is_array($payload['values'])) ? $payload['values'] : [];
            if (count($raw) > 8) return ['error' => 'too many values'];
            $values = [];
            foreach ($raw as $k => $v) {
                if (!is_string($k) || (!is_string($v) && !is_numeric($v))) continue;
                $v = (string) $v;
                if (strlen($v) > 1024) return ['error' => 'value too long'];
                $values[strtolower($k)] = $v;
            }

            $live = $this->liveCondValues($payload, $project_id, $instrument);
            if ($live === null) return ['error' => 'malformed request'];
            $rule = $this->uniqueRuleFor($this->getRules($project_id, [$field]), $field, $project_id, $record, $event_id, $instrument, $repeat_instance, $isAuthenticated && !$isSurvey, $live);
            if ($rule === null) return ['error' => $refused];
            if (!$isAuthenticated) {
                // An unauthenticated caller gets an answer ONLY for a rule whose
                // designer opted surveys in...
                if (empty($rule['uniqueSurveys'])) return ['error' => 'not enabled on surveys'];
                // ...never for an identifying field (the configuration channels
                // already refuse that opt-in; this re-check is what actually
                // holds the line for a caller who skips the survey machinery
                // altogether — security scan 15 Jul 2026 advisory)...
                //
                // FAIL CLOSED when identifier status is UNVERIFIABLE (F3). All
                // three identifier gates (the two config channels and this one)
                // read the same data dictionary; projectIdentifierFields() returns
                // null on any getDataDictionary failure/empty result, and
                // isIdentifier(null, …) is false — so a transient dictionary read
                // failure would silently reopen the unauthenticated existence
                // oracle this check exists to close. findCollision() does NOT need
                // the dictionary, so it would still answer. Refuse instead: an
                // unauthenticated caller loses only a convenience (the post-save
                // audit and the Validation scan still cover the field), while a
                // known-identifier field stays protected even when the dictionary
                // momentarily cannot be read.
                // The refusal covers the primary field AND every composite "with"
                // field (H-01), not just the primary: an "already used" answer whose
                // key includes an identifying value is the same existence oracle.
                // And every "also" field: "already used" there says the value is
                // saved in it.
                $identifiers = $this->projectIdentifierFields($project_id);
                $withFields = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                $alsoFields = (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso'])) ? $rule['uniqueAlso'] : [];
                if ($identifiers === null
                        || self::firstIdentifier($identifiers, array_merge([$field], $withFields, $alsoFields)) !== null) {
                    return ['error' => 'not enabled on surveys'];
                }
                // ...nor about a field off the survey's own instrument, which
                // the respondent may never see (surveyAlsoProblem; the
                // configuration channels refuse it, this holds the line).
                if (self::surveyAlsoProblem($this->dataDictionary($project_id), $field, $alsoFields) !== null) {
                    return ['error' => 'not enabled on surveys'];
                }
                // ...nor about a tagged field of another instrument (UNQ-5):
                // the link to a public screening survey must not test the
                // tokens of an invitation-only one.
                if (!self::onSurveyInstrument($this->dataDictionary($project_id), $field, $instrument)) {
                    return ['error' => 'not enabled on surveys'];
                }
                // ...and never faster than the throttle allows.
                if ($this->surveyRateLimited($project_id)) return ['error' => 'too many checks — slow down'];
            } else {
                // A signed-in caller is throttled too, and answered only about
                // forms they may open: "already used" on a field of a form the
                // user has no access to is a read of that form by another door.
                // Its own answer, as exists-check gives it: the page says
                // "could not check" and why, instead of nothing (UNQ-3).
                if ($this->signedInRateLimited($project_id)) {
                    return ['unknown' => true, 'record' => null, 'throttled' => true,
                            'why' => 'too many checks in the last minute — wait a moment'];
                }
                // An "also" field is searched too, so the caller must be able
                // to open its form as well.
                $withFields = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
                $alsoFields = (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso'])) ? $rule['uniqueAlso'] : [];
                if ($this->firstUnreadableField($project_id, $user_id, array_merge([$field], $withFields, $alsoFields)) !== null) {
                    return ['error' => 'not a checkable field'];
                }
                // The record's saved values are read below; one of another
                // Data Access Group is not this caller's to read.
                if ($this->recordBeyondCallerGroup($project_id, $record, $group_id)) {
                    return ['error' => 'record not available'];
                }
            }

            $with  = (isset($rule['uniqueWith']) && is_array($rule['uniqueWith'])) ? $rule['uniqueWith'] : [];
            $also  = (isset($rule['uniqueAlso']) && is_array($rule['uniqueAlso'])) ? $rule['uniqueAlso'] : [];
            $scope = isset($rule['uniqueScope']) ? $rule['uniqueScope'] : 'project';
            // A date arrives as the field shows it; the comparison is on stored values.
            $values = $this->storedFormOf($project_id, $values);
            // A Missing Data Code is not a value anyone else can have "used".
            if (self::isMissingCode(isset($values[$field]) ? $values[$field] : null, $this->missingDataCodes($project_id))) {
                return ['used' => false, 'record' => null];
            }

            // Resolve composite "with" values the browser could not read (H-03). A
            // field that is not on the rendered instrument is sent as "" by the
            // client, which would compare against blank and MISS a real collision.
            // For such a field, read its saved value on the server (authoritative);
            // a field ON the instrument keeps the client's live value (unsaved edits
            // count), mirroring how "when"/"assert" conditions fold. The resolved
            // values feed ONLY the in-PHP comparison — nothing off-page is ever
            // returned to the page, so no record value leaks (SEC-005 posture holds).
            if ($with && $record !== null && $record !== '') {
                $onForm = $this->fieldsOnInstrument($project_id, $instrument);
                $offPage = [];
                foreach ($with as $w) {
                    // Sent by the page: its value counts, unsaved edits included.
                    // Left out (not on this page: another form, or another page
                    // of a multi-page survey): read from the saved record. A
                    // blank sent for a field of another form counts as left out.
                    if (array_key_exists($w, $values)
                            && ($values[$w] !== '' || ($onForm !== null && isset($onForm[$w])))) continue;
                    $offPage[] = $w;
                }
                if ($offPage) {
                    $withRes = [];
                    try {
                        $saved = $this->readValues($project_id, $record, $offPage, $event_id, $instrument, $repeat_instance, false, $withRes);
                        foreach ($offPage as $w) {
                            if (isset($saved[$w]) && !is_array($saved[$w])) $values[$w] = (string) $saved[$w];
                        }
                    } catch (\Throwable $e) {
                        foreach ($offPage as $w) $withRes[$w] = 'unreadable';
                    }
                    // A part that was not read here is not a blank (UNQ-2):
                    // blank against blank called every reuse at another site
                    // a duplicate.
                    foreach ($offPage as $w) {
                        if (isset($withRes[$w]) && $withRes[$w] !== 'ok') {
                            return ['unknown' => true, 'record' => null]
                                + ($isAuthenticated && !$isSurvey ? ['why' => 'the composite key ' . self::resolutionProblem($withRes[$w], $w)] : []);
                        }
                    }
                }
            }
            // $narrow = true: this is the live endpoint, the amplification vector —
            // let REDCap filter to candidate matches first (F4). Only a hit spares
            // the export of the tagged fields: a miss ("not used") is confirmed by
            // a full read, which a survey makes only within its read budget
            // (findCollision), shared with @UVEXISTS at THROTTLE_SURVEY_FULL_READS
            // a minute. A busy survey therefore answers "not known" sooner, and a
            // signed-in user's every miss is one export of those fields. The
            // post-save audit's findCollision call keeps the full, authoritative scan.
            $mayFullRead = $isAuthenticated ? null
                : function () use ($project_id) { return $this->surveyFullReadAllowed($project_id); };
            // A record the page drew as new is not left out (UNQ-6): its id is
            // REDCap's guess, and another user may have saved a record under
            // it since. A forged flag only widens the comparison.
            $nr = isset($payload['newRecord']) ? $payload['newRecord'] : null;
            $exclude = ($nr === true || $nr === 1 || $nr === '1' || $nr === 'true') ? null : $record;
            $col = $this->findCollision($project_id, $field, $with, $scope, $values, $exclude, $event_id, $group_id, true,
                                        empty($rule['caseSensitive']), $mayFullRead, $also);
            // Not settled (the read budget refused the read it needed, or the
            // read failed): not "free", which would let a known duplicate
            // through a save that asked again.
            if ($col === false) return ['unknown' => true, 'record' => null]
                + ($isAuthenticated && !$isSurvey ? ['why' => 'the saved values could not be compared just now'] : []);
            if ($col === null) return ['used' => false, 'record' => null];

            // The colliding record id goes ONLY to an authenticated user, and a
            // survey page never names a record even if a staff session happens
            // to be open in the same browser.
            $recOut = null;
            $fieldOut = null;
            if ($isAuthenticated && !$isSurvey) {
                $recOut = $col['record'];
                if ($group_id !== null && $group_id !== '') {
                    // A DAG-bound user may learn THAT the value is used, but a
                    // record id outside their DAG is not theirs to see.
                    $userDag = ScanPageView::dagNameOf($group_id);
                    if ($userDag === null || $col['dag'] !== $userDag) $recOut = null;
                }
                // Found in an "also" field: say which, beside a record the
                // caller may be told about. The caller may open its form
                // (checked above); a record of another group is named by
                // neither its id nor the field that holds the value.
                if ($recOut !== null && isset($col['field']) && $col['field'] !== $field) $fieldOut = $col['field'];
            }
            return ['used' => true, 'record' => $recOut] + ($fieldOut !== null ? ['field' => $fieldOut] : []);
        } catch (\Throwable $e) {
            return ['error' => 'unique check failed']; // client fails open; no detail leaks
        }
    }

    /**
     * The live @UVEXISTS lookup: is this value already saved where the rule
     * looks? Same posture as unique-check, in this order:
     *   1. the field must carry a live exists rule; where to look is re-read
     *      from that stored rule, never taken from the request;
     *   2. an unauthenticated caller (a survey) is answered only for a rule that
     *      opted in, never when any field the lookup touches is an Identifier
     *      (unreadable flags refuse), and never faster than the survey throttle;
     *   3. a signed-in caller is throttled per session and answered only when
     *      they may open every form the lookup reads;
     *   4. "match" fields the page did not send are read from the saved record;
     *   5. a rule that searches another project then meets crossLookup(): that
     *      project's agreement, the caller's standing there, its budget;
     *   6. the reply is found / not-found / unknown. A survey never gets a reason
     *      or a record; staff in a DAG get the record only when it is in their
     *      own group, staff in none get it always. A record of another project
     *      is never returned. A "not found" whose value is saved, but only in
     *      entries that fail the rule's "where", carries filtered: true for
     *      staff; a survey gets the plain "not found".
     * The branch of a branched rule is the one the page is enforcing: chosen
     * from the values the page sent for its "when" fields ("cond"), and from
     * saved values for every other field (activeRuleFor).
     * Every failure answers unknown or an error, which the browser shows as
     * "could not check". It blocks only where Save asked again about a "not
     * found" the page already held: that answer stands (QRID keepPrior).
     */
    private function existsCheck($payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $user_id, $group_id)
    {
        $unknown = function ($why = null) use ($user_id, $survey_hash) {
            $staff = ($user_id !== null && $user_id !== '') && ($survey_hash === null || $survey_hash === '');
            return ['state' => 'unknown', 'record' => null, 'why' => $staff ? $why : null];
        };
        try {
            $isAuthenticated = ($user_id !== null && $user_id !== '');
            $isSurvey = ($survey_hash !== null && $survey_hash !== '');
            $refused = $isAuthenticated ? 'not a checkable field' : 'not enabled on surveys';   // one wording, as unique-check (UNQ-9)
            $field = (isset($payload['field']) && is_string($payload['field'])) ? strtolower(trim($payload['field'])) : '';
            if ($field === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) return ['error' => $refused];
            $raw = (isset($payload['values']) && is_array($payload['values'])) ? $payload['values'] : [];
            if (count($raw) > 8) return ['error' => 'too many values'];
            $values = [];
            foreach ($raw as $k => $v) {
                if (!is_string($k) || (!is_string($v) && !is_numeric($v))) continue;
                $v = (string) $v;
                if (strlen($v) > 1024) return ['error' => 'value too long'];
                $values[strtolower($k)] = $v;
            }
            $live = $this->liveCondValues($payload, $project_id, $instrument);
            if ($live === null) return ['error' => 'malformed request'];
            $rule = $this->activeRuleFor($this->getRules($project_id, [$field]), 'exists', $field, $project_id, $record,
                $event_id, $instrument, $repeat_instance, false, $live);
            if ($rule === null) return ['error' => $refused];
            $locals = (isset($rule['existsLocal']) && is_array($rule['existsLocal'])) ? $rule['existsLocal'] : [];
            $targets = (isset($rule['existsTargets']) && is_array($rule['existsTargets'])) ? $rule['existsTargets'] : [];
            if (!$isAuthenticated) {
                if (empty($rule['existsSurveys'])) return ['error' => 'not enabled on surveys'];
                $touch = array_merge([$field], $locals, $targets);
                // Another project's fields are checked there (crossCaller).
                if (($rule['existsIn'] ?? null) === 'record' && empty($rule['existsPid'])) {
                    $pk = $this->recordIdFieldOf($project_id);
                    if ($pk === '') return ['error' => 'not enabled on surveys'];
                    $touch[] = $pk;
                }
                $ids = $this->projectIdentifierFields($project_id);
                if ($ids === null || self::firstIdentifier($ids, $touch) !== null) return ['error' => 'not enabled on surveys'];
                if ($this->surveyRateLimited($project_id)) return ['error' => 'too many checks — slow down'];
            } else {
                // Flagged, so a rule that blocks holds the save until the next
                // answer instead of letting the value through (UNQ-3).
                if ($this->signedInRateLimited($project_id)) {
                    return $unknown('too many checks in the last minute — wait a moment') + ['throttled' => true];
                }
                // Generic on purpose: naming the field would tell the page where
                // the rule looks. The fields a rule copies ("return") are read
                // too; another project's are checked there (crossCaller).
                if ($this->firstUnreadableField($project_id, $user_id, self::existsReadFields($rule, $field)) !== null) {
                    return $unknown('you do not have access to every form this lookup reads');
                }
                if ($this->recordBeyondCallerGroup($project_id, $record, $group_id)) {
                    return $unknown('this record is not in your Data Access Group');
                }
            }
            $value = isset($values[$field]) ? Logic::lookupTrim($values[$field]) : '';
            if ($value === '') return ['error' => 'nothing to look up'];
            // Values arrive as the page shows them; the lookup compares stored ones.
            $values = $this->storedFormOf($project_id, $values);
            $value = Logic::lookupTrim($values[$field]);
            // A Missing Data Code is no value to look up (the page never asks).
            $codes = $this->missingDataCodes($project_id);
            if (self::isMissingCode($value, $codes)) return ['error' => 'nothing to look up'];
            $lv = [];
            $offPage = [];
            $onForm = $locals ? $this->fieldsOnInstrument($project_id, $instrument) : null;
            foreach ($locals as $lf) {
                // A match field the page shows is sent, blank or not. One it does
                // not show (another form, or another page of a multi-page
                // survey) is left out and read from the saved record; so is a
                // blank sent for a field of another form.
                if (array_key_exists($lf, $values)
                        && (Logic::lookupTrim($values[$lf]) !== '' || ($onForm !== null && isset($onForm[$lf])))) {
                    $lv[$lf] = Logic::lookupTrim($values[$lf]);
                    continue;
                }
                $offPage[] = $lf;
            }
            if ($offPage) {
                if ($record === null || $record === '') {
                    foreach ($offPage as $lf) $lv[$lf] = '';
                } else {
                    $saved = $this->readValues($project_id, $record, $offPage, $event_id, $instrument, $repeat_instance, false);
                    foreach ($offPage as $lf) {
                        $lv[$lf] = (isset($saved[$lf]) && !is_array($saved[$lf])) ? Logic::lookupTrim($saved[$lf]) : '';
                    }
                }
            }
            foreach ($lv as $lf => $v) {
                if ($v === '') return $unknown('[' . $lf . '] is blank, so there is nothing to match against yet');
                if (self::isMissingCode($v, $codes)) return $unknown('[' . $lf . '] holds a missing data code, so there is nothing to match against');
            }
            $dag = null;
            if (($rule['existsScope'] ?? 'project') === 'dag') {
                $rd = $this->recordDagOf($project_id, $record);
                if ($rd === false) return $unknown('the record\'s Data Access Group could not be read');
                // A record not saved yet is created in the user's own group, or in none.
                // A group whose name cannot be read is not "no group" (dagNameOf).
                if ($rd['found']) {
                    $dag = $rd['dag'];
                } elseif ($group_id !== null && $group_id !== '') {
                    $dag = ScanPageView::dagNameOf($group_id);
                    if ($dag === null) return $unknown('your Data Access Group could not be read');
                }
            }
            $opts = ['self' => $record];
            if (!$isAuthenticated) {
                // Anyone can produce misses, and a miss is confirmed by a read of
                // the whole searched field: a budget per project keeps that
                // read from being a lever.
                $opts['mayFullRead'] = function () use ($project_id) { return $this->surveyFullReadAllowed($project_id); };
            } elseif ($group_id !== null && $group_id !== '' && empty($rule['existsPid'])) {
                $opts['callerDag'] = ScanPageView::dagNameOf($group_id);
                if ($opts['callerDag'] === null) return $unknown('your Data Access Group could not be read');
            }
            // "in" on this form (EXS-6): the page sent what that field holds
            // now. The search leaves the record being edited out, and that
            // value counts for it below, so the page judges what the audit
            // judges once the record is saved.
            $selfIn = null;
            $inField = isset($rule['existsIn']) ? (string) $rule['existsIn'] : '';
            if (empty($rule['existsPid']) && !empty($rule['existsSelfIn']) && $inField === (string) $rule['existsSelfIn']
                    && $inField !== $field && array_key_exists($inField, $values)) {
                $selfIn = Logic::lookupTrim($values[$inField]);
                if ($record !== null && $record !== '') $opts['skip'] = $record;
            }
            // "return": values go only to a signed-in person on a data-entry
            // form. Any other caller's lookup asks for none, so none is read,
            // and another project logs none as handed over (logCrossProbe).
            $handOver = $isAuthenticated && !$isSurvey;
            $ask = $handOver ? $rule : self::withoutReturn($rule);
            // Another project: its agreement, the caller's standing there and its
            // budget decide; the group that matters is the caller's group THERE.
            $r = !empty($rule['existsPid'])
                ? $this->crossLookup($project_id, $ask, $value, $lv, $isAuthenticated ? 'staff' : 'survey', $opts)
                : $this->findExisting($project_id, $ask, $value, $lv, $event_id, $dag, true, $opts);
            if ($selfIn !== null && $r['state'] !== 'found') $r = $this->existsSelfAnswer($project_id, $ask, $r, $value, $selfIn, $inField, $codes);
            if ($r['state'] === 'unknown') {
                return $unknown(isset($r['why']) && $r['why'] !== null ? $r['why'] : 'the saved values could not be read just now');
            }
            $recOut = null;
            if ($r['state'] === 'found' && $isAuthenticated && !$isSurvey && ($rule['existsIn'] ?? null) !== 'record'
                    && empty($rule['existsPid'])) {
                $recOut = $r['record'];
                if ($group_id !== null && $group_id !== '') {
                    $userDag = ScanPageView::dagNameOf($group_id);
                    if ($userDag === null || $r['dag'] !== $userDag) $recOut = null;
                }
            }
            $resp = ['state' => $r['state'], 'record' => $recOut];
            // Saved, but in no entry that meets "where": staff are told, a
            // survey respondent gets the plain "not found".
            if ($r['state'] === 'not-found' && !empty($r['filtered']) && $isAuthenticated && !$isSurvey) $resp['filtered'] = true;
            // "return": the values for the fields here, as the page shows them
            // (a survey never gets them; the rule refuses "surveys" anyway).
            // Keyed by the field here: the page never learns which fields
            // they came from.
            $ret = (isset($ask['existsReturn']) && is_array($ask['existsReturn'])) ? $ask['existsReturn'] : [];
            if ($ret && $r['state'] === 'found') {
                if (isset($r['returned']) && is_array($r['returned'])) {
                    $vals = [];
                    foreach ($ret as $s => $t) $vals[$t] = isset($r['returned'][$s]) ? (string) $r['returned'][$s] : '';
                    $resp['values'] = $this->displayFormOf($project_id, $vals);
                } elseif (isset($r['returnConflict'])) {
                    $resp['fill'] = 'conflict';
                    $resp['count'] = (int) $r['returnConflict'];
                } elseif (!empty($r['returnWithheld'])) {
                    $resp['fill'] = 'withheld';
                }
            }
            return $resp;
        } catch (\Throwable $e) {
            return ['error' => 'lookup failed'];   // the client shows "could not check"; no detail leaks
        }
    }

    /**
     * The live answer once the record being edited counts (EXS-6): the search
     * ($r) left the record out, and the page holds $selfIn in the searched
     * field $in. When that is the value looked up, the record holds it. A
     * rule that tests only the value is then found (the record is not named:
     * it is the one being edited). A rule with "match", "where", "return" or
     * a named "event" tests more of the record than the page sent, so the
     * answer is unknown and the audit decides after the save. Otherwise $r.
     */
    private function existsSelfAnswer($pid, array $rule, array $r, $value, $selfIn, $in, array $codes)
    {
        if ($selfIn === '' || self::isMissingCode($selfIn, $codes)) return $r;
        $fold = empty($rule['caseSensitive']);
        $mark = $this->lookupMarks($pid, [$in])[$in];
        if (Logic::lookupKey($selfIn, $fold, $mark) !== Logic::lookupKey($value, $fold, $mark)) return $r;
        if (empty($rule['existsMatch']) && !isset($rule['existsWhere']) && empty($rule['existsReturn']) && empty($rule['existsEvent'])) {
            return ['state' => 'found', 'record' => null, 'dag' => null];
        }
        return ['state' => 'unknown', 'record' => null, 'dag' => null,
                'why' => 'the value is the one this form holds, which is checked when the record is saved'];
    }

    /**
     * The "cond" part of a live-lookup request: the values the page read for
     * the fields its "when" conditions test, so the server picks the branch the
     * page is enforcing (activeRuleFor). Only fields of the rendered instrument
     * are taken; every other field keeps its saved value. A checkbox arrives as
     * code => "1"/"0". The values are compared as sent, as the page compared
     * them. Absent: []. Malformed: null.
     */
    private function liveCondValues($payload, $pid, $instrument)
    {
        if (!is_array($payload) || !isset($payload['cond'])) return [];
        if (!is_array($payload['cond']) || count($payload['cond']) > 40) return null;
        $onForm = $this->fieldsOnInstrument($pid, $instrument);
        $out = [];
        foreach ($payload['cond'] as $k => $v) {
            if (!is_string($k)) return null;
            $k = strtolower($k);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $k)) return null;
            if (is_array($v)) {
                if (count($v) > 200) return null;
                $codes = [];
                foreach ($v as $c => $on) {
                    $c = (string) $c;
                    if ($c === '' || strlen($c) > 100 || (!is_string($on) && !is_numeric($on))) return null;
                    $codes[$c] = ((string) $on === '1') ? '1' : '0';
                }
                $v = $codes;
            } elseif (is_string($v) || is_numeric($v)) {
                $v = (string) $v;
                if (strlen($v) > 1024) return null;
            } else {
                return null;
            }
            if ($onForm !== null && isset($onForm[$k])) $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Whether a signed-in caller in a Data Access Group names a record of
     * another group, or one whose group cannot be read. The live lookups read
     * that record's saved values ("match" and "with" fields, "scope":"dag"), so
     * such a request is refused. A record not saved yet is fine: REDCap creates
     * it in the caller's group.
     */
    private function recordBeyondCallerGroup($pid, $record, $group_id)
    {
        if ($group_id === null || $group_id === '' || $record === null || $record === '') return false;
        $userDag = ScanPageView::dagNameOf($group_id);
        if ($userDag === null) return true;
        $rd = $this->recordDagOf($pid, $record);
        if ($rd === false) return true;
        return $rd['found'] && $rd['dag'] !== $userDag;
    }

    /**
     * Whether an unauthenticated @UVEXISTS lookup may read the whole searched
     * field now (tier 2 of the rate buckets, THROTTLE_SURVEY_FULL_READS per
     * project per minute). FAILS CLOSED, unlike the throttles: a refusal costs
     * the respondent a "could not check", which a survey shows as nothing,
     * while a counter that cannot be kept would leave the read unbounded.
     */
    private function surveyFullReadAllowed($pid)
    {
        try {
            if (!$pid) return false;
            $db = new Scan\ModuleDb($this);
            $bucket = ((int) floor(time() / 60)) * self::RATE_TIERS + 2;
            $db->exec('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [(int) $pid, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            if (!isset($r[0][0]) || $r[0][0] === null) return false;
            return (int) $r[0][0] <= self::THROTTLE_SURVEY_FULL_READS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Throttle for SIGNED-IN callers of the live lookups, per session and project.
     *
     * Keyed on the session, which for this caller is not something they can
     * shed: the session IS the sign-in, so a request without it is not signed
     * in and meets the survey path's guards instead. One budget covers both
     * endpoints. Fails open (no session, unreadable state), like the survey
     * throttle: the live check is a convenience and the audit is the net.
     */
    private function signedInRateLimited($pid)
    {
        try {
            if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) return false;
            $key = 'uvalidate_lookup_hits_' . (int) $pid;
            $now = time();
            $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
            $hits = array_values(array_filter($hits, function ($t) use ($now) {
                return is_int($t) && ($now - $t) < 60;
            }));
            if (count($hits) >= self::THROTTLE_SIGNED_IN) { $_SESSION[$key] = $hits; return true; }
            $hits[] = $now;
            $_SESSION[$key] = $hits;
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The first of $fields whose form the signed-in caller may not open, or
     * null when they may open them all. A field the dictionary cannot place, or
     * rights that cannot be read, count as not readable (fail closed).
     */
    /**
     * Whether this person may change $form: REDCap's levels 1 (view and edit)
     * and 3 (edit survey responses); 2 (read only) and 0 may not. True is an
     * administrator (userFormRights). Fails closed, as mayReadForm does.
     */
    private static function mayEditForm($rights, $form)
    {
        if ($rights === true) return true;
        if (!is_array($rights) || !array_key_exists($form, $rights)) return false;
        return ScanPageView::formLevelEdits($rights[$form]);
    }

    /**
     * The values of $returns (field => text) that go into a form the person
     * saving may not change (EXS-3); [] for a save with no signed-in user
     * (a job), which REDCap runs with no form rights to ask.
     */
    private function returnsBarred($pid, array $returns)
    {
        if (!$returns || $this->currentUsername() === null) return [];
        $rights = $this->userFormRights($pid);
        $dd = $this->dataDictionary($pid);
        $out = [];
        foreach ($returns as $f => $text) {
            $form = (is_array($dd) && isset($dd[$f]['form_name'])) ? (string) $dd[$f]['form_name'] : '';
            if ($form === '' || !self::mayEditForm($rights, $form)) $out[(string) $f] = $text;
        }
        return $out;
    }

    private function firstUnreadableField($pid, $user_id, array $fields)
    {
        $rights = $this->callerFormRights($pid, $user_id);
        $dd = $this->dataDictionary($pid);
        foreach ($fields as $f) {
            $f = (string) $f;
            if ($f === '') continue;
            $form = (is_array($dd) && isset($dd[$f]['form_name'])) ? (string) $dd[$f]['form_name'] : '';
            if ($form === '' || !self::mayReadForm($rights, $form)) return $f;
        }
        return null;
    }

    /**
     * The form rights of the signed-in caller of an AJAX action: the framework
     * user's, or - when this request has no framework user - those REDCap
     * holds for $user_id, the identity the framework authenticated.
     */
    private function callerFormRights($pid, $user_id)
    {
        $r = $this->userFormRights($pid);
        if ($r !== null || $this->currentUsername() !== null || $user_id === null || $user_id === '') return $r;
        try {
            if (is_callable(['\REDCap', 'getUserRights'])) {
                $all = \REDCap::getUserRights((string) $user_id);
                if (is_array($all) && isset($all[$user_id]['forms']) && is_array($all[$user_id]['forms'])) {
                    return $all[$user_id]['forms'];
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * Throttle for the UNAUTHENTICATED (survey) uniqueness path.
     *
     * TWO TIERS THAT BOTH RUN, which is the correction. They were alternatives:
     * tier 1 returned as soon as it passed, so tier 2 was reached only by a
     * caller carrying no session at all. That made the throttle keyed, in
     * practice, on something the caller chooses - discard the cookie between
     * requests and each one starts a fresh 30-request budget, without ever
     * meeting the per-project cap. The evasion costs an attacker one header.
     *
     *   (1) With an active session (a normal survey respondent): a per-SESSION
     *       window, cheap and touching no shared storage. It can only ever
     *       refuse; passing it no longer ends the check.
     *   (2) Always: a per-PROJECT fixed window (F5) held as a COUNTER IN THE
     *       DATABASE, incremented by one statement, so a flood is bounded even
     *       with nothing to key a session on. Sessioned and sessionless traffic
     *       are counted in SEPARATE buckets with separate caps, so extending
     *       this tier to normal traffic bounds the evasion without turning a
     *       busy survey into an outage.
     *
     *       Tier (2) was a read-modify-write over a system setting holding an
     *       array of timestamps, and concurrency defeated it exactly under the
     *       traffic it was written for: every concurrent request read the same
     *       array, appended one entry, and the last write won. See the tier's
     *       own comment for why the window is fixed rather than sliding.
     *
     * Still defence in depth, not THE defence: a single TARGETED probe is
     * inherent to answering "is this value already used?" at all, which is why
     * the survey opt-in is refused on Identifier fields and off by default
     * everywhere else. Fails OPEN on any error — the live check is a convenience,
     * never a gate on data entry.
     */
    private function surveyRateLimited($pid = null)
    {
        $window = 60;
        $now = time();
        // Tier (1): per-session window for a caller that has a session.
        //
        // IT DOES NOT RETURN WHEN IT PASSES, and that is the whole point of the
        // tier. It used to, which made the two tiers ALTERNATIVES: tier 2 was
        // reached only by a caller with no session at all, so the cheapest
        // possible evasion - discard the session cookie between requests, which
        // costs an attacker one header and nothing else - took a fresh
        // 30-request budget every time and never once met the per-project cap.
        // A budget keyed on something the caller chooses is not a budget.
        $sessioned = false;
        try {
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                // The sessioned bucket only for a session with history: the
                // first check it made is SESSION_HISTORY seconds old or more.
                // The time lives on the server, so a caller cannot forge it,
                // only wait for it.
                $sinceKey = 'uvalidate_unique_since';
                $since = (isset($_SESSION[$sinceKey]) && is_int($_SESSION[$sinceKey]) && $_SESSION[$sinceKey] <= $now)
                    ? $_SESSION[$sinceKey] : null;
                if ($since === null) { $since = $now; $_SESSION[$sinceKey] = $now; }
                $sessioned = ($now - $since) >= self::SESSION_HISTORY;
                $key = 'uvalidate_unique_hits';
                $hits = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
                $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                    return is_int($t) && ($now - $t) < $window;
                }));
                if (count($hits) >= self::THROTTLE_SESSION) { $_SESSION[$key] = $hits; return true; }
                $hits[] = $now;
                $_SESSION[$key] = $hits;
            }
        } catch (\Throwable $e) {
            // Session state that cannot be read is not session state to trust.
            // The project tier below still runs, which is the tier that does
            // not depend on the caller keeping anything.
            $sessioned = false;
        }
        // Tier (2): bound the flood per project, whether or not tier 1 ran.
        //
        // A COUNTER THE DATABASE OWNS, not a timestamp array this process reads,
        // edits and writes back. The read-modify-write was defeated by the exact
        // traffic the tier was written for: N concurrent requests all read the
        // same array, each appended one entry, and the last write won - so a
        // flood of 600 concurrent checks recorded a handful of hits and the cap
        // was never reached. There is no read here at all. One statement
        // increments, and the value it returns is the count after this request.
        //
        // A FIXED WINDOW, not a sliding one, and that is a deliberate trade. A
        // sliding window needs the timestamps, and keeping the timestamps is
        // what made the counter loseable. The cost is that a burst straddling a
        // boundary can spend up to 2 x $pmax inside one 60-second span; the
        // benefit is that no increment can ever be lost. For a throttle whose
        // purpose is to bound a flood rather than to meter it, that is the right
        // way round.
        try {
            if (!$pid) return false;
            // TWO BUDGETS, NOT ONE, because the two populations are not alike.
            // Sessioned traffic is mostly real respondents, and a busy public
            // survey can legitimately spend more than the sessionless cap: tier
            // 1 allows each session 30 a minute, so 600 is only twenty people
            // typing at once. Charging both populations to one bucket would
            // have turned this hardening change into an outage for exactly the
            // projects that use the feature most. Separate buckets mean the
            // sessionless cap keeps the value it was chosen for, and the
            // sessioned cap only has to be low enough to bound enumeration.
            // A caller who rotates the cookie stays in the 600 bucket (about
            // 28 hours for a six-digit space). One who keeps two hundred
            // sessions open past their first minute reaches the 6,000 cap
            // (about 2.8 hours), on an endpoint whose real defences are the
            // per-rule opt-in and the Identifier refusal.
            //
            // NOT SETTINGS. Every other tunable in this module is a setting,
            // deliberately; these are not, because the failure mode of a
            // mistyped rate limit is a security control quietly set to zero,
            // and there is no reading of it that an administrator needs.
            $pmax = $sessioned ? self::THROTTLE_PROJECT_SESSIONED : self::THROTTLE_PROJECT_ANON;
            $db = new Scan\ModuleDb($this);
            // ONE BUCKET COLUMN, SEVERAL NAMESPACES. The primary key is
            // (project_id, bucket) and a third dimension would mean an ALTER on
            // a table that is now created with IF NOT EXISTS on every
            // installation - so the tier rides in the low bits instead
            // (RATE_TIERS). INT UNSIGNED holds the minute-counter times six
            // until the year 3300.
            $bucket = ((int) floor($now / $window)) * self::RATE_TIERS + ($sessioned ? 1 : 0);
            // LAST_INSERT_ID(expr) on both paths, so the fresh-bucket INSERT and
            // the existing-bucket UPDATE both answer with the number they wrote.
            // The table has no AUTO_INCREMENT, so without it the insert path
            // would report whatever the session last happened to set.
            //
            // execNoCount(): what this write answers with is LAST_INSERT_ID(),
            // read below, and never its row count. exec() fetched ROW_COUNT()
            // after it on every live uniqueness check of every survey page.
            $db->execNoCount('INSERT INTO ' . Scan\Schema::table('rate_bucket') . '
                (project_id, bucket, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [(int) $pid, $bucket]);
            $r = $db->select('SELECT LAST_INSERT_ID()', []);
            // "I DO NOT KNOW" IS NOT "UNDER THE CAP". This read used to answer
            // 0 for any shape it could not walk, which is silently the most
            // permissive answer available: no throttle, no pruning, and nothing
            // logged. ModuleDb::exec() already refuses to guess a row count for
            // exactly this reason and the read-back must not be more trusting
            // than the write. Raised rather than returned, so it lands in the
            // catch below and is recorded like any other storage failure.
            if (!isset($r[0][0]) || $r[0][0] === null) {
                throw new \RuntimeException('the counter did not report a value');
            }
            $hits = (int) $r[0][0];
            // Self-pruning, and only on the request that created the bucket, so
            // this is one DELETE per project per window rather than one per
            // check. Two windows of slack because a request can be in flight
            // across a boundary - two windows of RATE_TIERS slots each, because
            // the bucket number interleaves the tiers.
            if ($hits === 1) {
                $db->execNoCount('DELETE FROM ' . Scan\Schema::table('rate_bucket')
                    . ' WHERE project_id = ? AND bucket < ?', [(int) $pid, $bucket - 2 * self::RATE_TIERS]);
            }
            return $hits > $pmax;
        } catch (\Throwable $e) {
            // FAILS OPEN, as the whole method does: the live check is a
            // convenience and never a gate on data entry, so a database that
            // is briefly unreachable must not start refusing survey responses.
            //
            // BUT IT SAYS SO. Failing open in silence is how this tier came to
            // be inert on every default installation for a whole release - the
            // table lived behind the durable scan's opt-in flag, the increment
            // threw on every request, and the only evidence was an absence.
            // An operator cannot notice an absence. One log row per project per
            // window is enough to see it and cheap enough to survive a flood.
            $this->noteThrottleUnavailable($pid, (int) floor($now / $window), $e);
            return false;
        }
    }

    /**
     * Record that the sessionless throttle could not reach its counter, at most
     * once per project per window.
     *
     * The marker is a system setting rather than the counter's own table, for
     * the obvious reason that this runs precisely when that table cannot be
     * reached. A lost update here costs one duplicate log row, which is why the
     * read-modify-write that was wrong for the counter is right for the marker.
     */
    private function noteThrottleUnavailable($pid, $bucket, \Throwable $e)
    {
        try {
            $key  = 'uv_throttle_down_' . (int) $pid;
            $seen = $this->getSystemSetting($key);
            if ((string) $seen === (string) $bucket) return;      // already said so this window
            $this->setSystemSetting($key, (string) $bucket);
            // The CLASS, not the message. A message from here can name the
            // installation's schema and its database user to a caller who is
            // unauthenticated by definition; the class plus the redacted detail
            // is what DbError exists to produce.
            $this->log('uv-throttle-storage-unavailable', [
                'project_id' => (int) $pid,
                'class'      => get_class($e),
                'detail'     => Scan\DbError::safe($e),
            ]);
        } catch (\Throwable $ignored) {
            // A failure to record a failure is not worth a second failure.
        }
    }

    /**
     * The live unique rule covering one field, flattened to its active branch.
     * Branch selection mirrors auditRule: conditions are evaluated against the
     * record's SAVED values (the client gates itself on live values before
     * calling). Returns null when no unique rule covers the field, or the
     * branch situation is unresolvable (conflict / unparseable) — the client
     * then fails open and the audit logs the config problem on save.
     */
    private function uniqueRuleFor(array $rules, $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead = false, array $live = [])
    {
        return $this->activeRuleFor($rules, 'unique', $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead, $live);
    }

    /**
     * The live rule of one MODE covering one field, flattened to its active
     * branch (see uniqueRuleFor), or null. Shared by the two live lookups.
     *
     * $live: values the page sent for the fields its "when" conditions read,
     * already limited to the rendered instrument (liveCondValues). They win
     * over the saved values, because the page picked its branch from them: a
     * selector just changed and not saved yet, or a record not saved at all,
     * must not be judged by another branch than the one the page enforces.
     */
    private function activeRuleFor(array $rules, $mode, $field, $pid, $record, $event_id, $instrument, $repeat_instance, $allowTemporalRead = false, array $live = [])
    {
        foreach ($rules as $r) {
            if (!empty($r['configError'])) continue;
            if (ModeRegistry::modeOfType(isset($r['type']) ? $r['type'] : '') !== $mode) continue;
            if (empty($r['fields']) || !is_array($r['fields']) || !in_array($field, $r['fields'], true)) continue;
            // Record-local rules are rendered as advisory assertions. The legacy
            // no-auth uniqueness endpoint must never reinterpret them as project scope.
            if (($r['uniqueScope'] ?? null) === 'record') return null;
            if (TemporalRules::extended($r) && !empty($r['branches'])) {
                if (!$allowTemporalRead || $record === null || $record === '') return null;
                $dd=$this->dataDictionary($pid) ?: [];$rights=$this->userFormRights($pid);
                foreach (TemporalRules::fields($r) as $source) {
                    if (!isset($dd[$source]['form_name']) || !self::mayReadForm($rights,$dd[$source]['form_name'])) return null;
                }
                $shape=$this->temporalShape($pid,$dd);$node=$this->temporalReadRecord($pid,$record,[$r]);
                $this->temporalBegin($pid);
                $p=$this->temporalPrepared($r,$shape,$node,['event'=>$event_id,'instrument'=>$instrument,'instance'=>$repeat_instance,'values'=>[]]);
                if ($p['problems'] || ($p['rule']['uniqueScope'] ?? null)==='record' || ($p['rule']['when'] ?? null)==='1=0') return null;
                return $p['rule'];
            }
            if (!isset($r['branches']) || !is_array($r['branches'])) return $r;

            $asts = [];
            $refs = [];
            $else = null;
            foreach ($r['branches'] as $bi => $b) {
                if (!isset($b['when']) || !is_string($b['when']) || $b['when'] === '') { $else = $bi; continue; }
                $p = Logic::parse($b['when']);
                if (empty($p['ok'])) return null;
                $asts[$bi] = $p['ast'];
                foreach (Logic::referencedFields($p['ast']) as $ref) $refs[$ref[0]] = true;
            }
            $values = ($record !== null && $record !== '')
                ? $this->readValues($pid, $record, array_keys($refs), $event_id, $instrument, $repeat_instance, true)
                : [];
            foreach ($live as $lf => $lv) {
                if (isset($refs[$lf])) $values[$lf] = $lv;
            }
            $active = [];
            foreach ($asts as $bi => $ast) {
                if (Logic::evaluate($ast, $values, Logic::BLANK_INERT, !empty($r['branches'][$bi]['caseSensitive']), $this->conditionNumbers($pid), $this->missingDataCodes($pid))) $active[] = $bi;
            }
            if (count($active) === 1) $pick = $active[0];
            elseif (!count($active) && $else !== null) $pick = $else;
            else return null;
            $b = $r['branches'][$pick];
            unset($b['when']);
            return array_merge(['type' => $r['type'], 'fields' => $r['fields']], $b);
        }
        return null;
    }
}
