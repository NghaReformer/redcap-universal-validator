<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Everything a run must decide before it reads its first record — and freeze.
 *
 * This file holds the parts of planning that are pure: how a rule is named so a
 * stored finding still points at it a year later, and how the whole validation
 * configuration is reduced to one hash that says whether two runs were checking
 * the same thing. The manifest walk and the fence capture live in
 * RecordManifestSource and SourceFence; plan() below assembles all three.
 *
 * WHY IDENTITY IS THE FIRST PROBLEM. The legacy scan cited a rule by ORDINAL —
 * its position in the array returned by getRules(), which concatenates the
 * settings rules and then the annotation rules in dictionary field order. Adding
 * one settings row, or moving one field in the Online Designer, renumbers every
 * annotation rule after it. That was harmless while findings lived for the
 * length of one request. The moment they persist, a stored "rule 7" silently
 * starts pointing at a different rule, and nothing in the data can detect it.
 *
 * TWO KINDS OF NAME, FOR TWO KINDS OF RULE:
 *
 *   A settings rule is a row an administrator created and can edit in place. Its
 *   name should survive an edit, so a persistent id stored on the row is the
 *   right answer and is used whenever one is present. Where none is (every
 *   project today, and any row saved by an older build), the fallback is derived
 *   from the rule's CONTENT plus its occurrence among identical siblings — which
 *   is stable under reordering, the failure the ordinal had.
 *
 *   An annotation rule is not a row at all; it is text in a field's annotation.
 *   Its name is therefore where it lives: the fields it is written on, the tag
 *   family, and its occurrence among identical siblings.
 *
 * ONE MERGE HAPPENS UPSTREAM AND IS NOT UNDONE HERE. AnnotationRules::groupMulti
 * collapses byte-identical tags into a single rule carrying several fields, so
 * two identical tags on the same field are already one rule before identity is
 * computed. That is correct — they ARE one rule — but it means identity cannot
 * separate them, and a test pins the behaviour so a future change to grouping
 * shows up here rather than as renamed findings.
 *
 * REVISION IS SEPARATE FROM IDENTITY, deliberately. Editing a rule's pattern
 * keeps its identity and changes its revision: the finding still points at the
 * same rule, and the report can say the rule changed since the run. Collapsing
 * the two would make every edit look like a new rule and every report an
 * incomparable island.
 *
 * PHP 7.4: static methods, no promotion, no match.
 */
final class ScanPlanner
{
    /**
     * The inputs a fingerprint must cover. Missing one is an error, not a
     * default — see fingerprint().
     */
    private static $required = ['engine', 'rules', 'ownership', 'structure', 'choices',
                                'gapPolicy', 'valueMode'];

    /**
     * Inputs that must NEVER enter the fingerprint, and why, keyed by the name a
     * caller would plausibly use.
     *
     * Wording is the whole list. A message catalog edit changes what a finding
     * READS AS; it cannot change whether the finding exists. If wording
     * invalidated the fingerprint, fixing a typo would force every project to
     * re-scan 100,000 records, so typos would not get fixed.
     */
    private static $forbidden = [
        'messages'  => 'message wording cannot change which findings exist',
        'catalog'   => 'message wording cannot change which findings exist',
        'labels'    => 'a label is how a finding is displayed, not whether it is one',
        'wording'   => 'message wording cannot change which findings exist',
    ];

    /** @var ScanStore */
    private $store;
    /** @var string the module's protected secret */
    private $key;

    public function __construct(ScanStore $store, $key)
    {
        $this->store = $store;
        $this->key = $key;
    }

    /**
     * Create a run, freeze its manifest, and hand it back ready to be worked.
     *
     * THE ORDER IS THE SAFETY. Everything that can refuse does so BEFORE a run
     * exists, because a refused start costs a message and an abandoned run costs
     * the project its scan slot until something expires it. Once the run does
     * exist, every remaining failure finishes it terminally rather than leaving
     * it - a planner that dies quietly is indistinguishable from one still
     * working, and the difference is a project that cannot scan again.
     *
     * @param array $req {
     *   source:        RecordManifestSource, required
     *   fence:         ?SourceFence
     *   rules:         array   the live rule list, as getRules() produced it
     *   settingsCount: int     how many of those came from settings
     *   ownership:     array   field => owning form
     *   structure:     array   events, arms, repeating shape
     *   choices:       array
     *   policy:        array   from ScanPolicy::resolve()
     *   engine:        string  the validation engine's version
     *   dagFilter:     ?string scope this run to one group
     *   createdBy:     string
     *   generation:    int
     *   pageSize:      int
     *   deadline:      ?float  unix time planning must stop by
     *   memCap:        ?int    bytes of resident memory planning must stay under
     * }
     * @return array{ok:bool, busy:bool, run:?array, why:?string, stats:array}
     */
    public function plan($pid, array $req)
    {
        $src = isset($req['source']) ? $req['source'] : null;
        if (!($src instanceof RecordManifestSource)) {
            return self::no('this installation cannot list the project\'s records without '
                . 'exporting it, so no scan may start');
        }
        $dag = isset($req['dagFilter']) && $req['dagFilter'] !== '' ? $req['dagFilter'] : null;
        if ($dag !== null && !$src->hasDag()) {
            // Running anyway would build a project-wide manifest for a user
            // entitled to one group. The persisted report is readable later by
            // whoever can open the page, so the scope has to be right when the
            // manifest is written, not when it is displayed.
            return self::no('this installation cannot tell which group a record belongs to, so a '
                . 'group-scoped scan cannot be run here');
        }
        $rules = isset($req['rules']) && is_array($req['rules']) ? $req['rules'] : [];
        if (!$rules) {
            return self::no('this project has no validation rules, so there is nothing to scan for');
        }

        // Names and revisions first: the fingerprint is computed OVER them, so a
        // rule that cannot be named is a rule the fingerprint cannot cover.
        $ids = self::identifyAll($rules, isset($req['settingsCount']) ? $req['settingsCount'] : 0);
        $ruleSpec = [];
        foreach ($ids as $i => $id) {
            $ruleSpec[] = ['id' => $id['source_id'], 'rev' => $id['revision'],
                           'ord' => $i + 1, 'origin' => $id['origin']];
        }
        try {
            $fp = self::fingerprint([
                'engine'    => isset($req['engine']) ? $req['engine'] : '',
                'rules'     => $ruleSpec,
                'ownership' => isset($req['ownership']) ? $req['ownership'] : [],
                'structure' => isset($req['structure']) ? $req['structure'] : [],
                'choices'   => isset($req['choices']) ? $req['choices'] : [],
                'gapPolicy' => isset($req['policy']['collectionGaps'])
                               ? $req['policy']['collectionGaps'] : 'separate',
                'valueMode' => isset($req['policy']['valueMode'])
                               ? $req['policy']['valueMode'] : 'locations',
            ]);
        } catch (\InvalidArgumentException $e) {
            // A fingerprint that could not be built is not a fingerprint that
            // can be skipped: without one, a resumed run cannot tell whether the
            // configuration moved underneath it.
            return self::no('this run could not be described well enough to detect a later '
                . 'configuration change, so it was not started');
        }

        // The opening fence, BEFORE the manifest. Captured after it, any change
        // during the walk would fall outside the window the run believes it
        // covers - which is the one gap a fence exists to close.
        $fence = isset($req['fence']) && $req['fence'] instanceof SourceFence ? $req['fence'] : null;
        $open = $fence === null ? null : $fence->now();

        $started = $this->store->startRun($pid, [
            'created_by'    => isset($req['createdBy']) ? $req['createdBy'] : '',
            'scope_dag'     => $dag,
            'generation_id' => isset($req['generation']) ? $req['generation'] : 1,
            'fingerprint'   => $fp,
            'fence_open'    => $open,
            'policy_json'   => json_encode(isset($req['policy']) ? $req['policy'] : []),
            'values_state'  => isset($req['policy']['valueMode'])
                               ? $req['policy']['valueMode'] : 'none',
        ]);
        if (empty($started['ok'])) {
            // Busy travels unchanged, including its deliberately uninformative
            // wording: which run holds the slot is never disclosed.
            return ['ok' => false, 'busy' => true, 'run' => null,
                    'why' => isset($started['why']) ? $started['why'] : null, 'stats' => []];
        }
        $run = $started['run'];
        $runId = (int) $run['run_id'];

        $walk = $this->stream($runId, $src, $pid, $dag, $req);
        if (!$walk['ok']) {
            // Terminal, not abandoned. See the method note: an abandoned run
            // holds the project's scan slot and looks like one still working.
            $this->store->finish($runId, ScanOutcome::derive(['failed' => true]));
            return ['ok' => false, 'busy' => false, 'run' => null, 'why' => $walk['why'],
                    'stats' => $walk['stats']];
        }

        $total = $this->store->freezeManifest($runId);
        if ($total === false) {
            $this->store->finish($runId, ScanOutcome::derive(['failed' => true]));
            return self::no('the record list could not be frozen, so the run was not started');
        }
        $walk['stats']['total'] = (int) $total;
        return ['ok' => true, 'busy' => false, 'run' => $this->store->run($pid, $runId),
                'why' => null, 'stats' => $walk['stats']];
    }

    /**
     * Walk the record source into the manifest, one bounded page at a time.
     *
     * Nothing accumulates: a page is read, hashed, written and dropped. That is
     * the whole difference between this and the legacy path, which built the
     * entire record set in PHP before examining any of it.
     */
    private function stream($runId, RecordManifestSource $src, $pid, $dag, array $req)
    {
        $pageSize = isset($req['pageSize']) ? max(1, (int) $req['pageSize']) : 1000;
        $deadline = isset($req['deadline']) ? $req['deadline'] : null;
        $memCap   = isset($req['memCap']) ? $req['memCap'] : null;
        $stats = ['pages' => 0, 'listed' => 0, 'appended' => 0, 'outOfScope' => 0];

        $cursor = null;
        $carry = [];
        while (true) {
            // Checked BETWEEN pages, never inside one. Stopping mid-page would
            // leave a partly written page whose records are indistinguishable
            // from records that do not exist.
            if ($deadline !== null && microtime(true) >= $deadline) {
                return ['ok' => false, 'stats' => $stats,
                        'why' => 'listing this project\'s records did not finish within the time '
                               . 'this server allows for one request'];
            }
            if ($memCap !== null && memory_get_usage(true) >= $memCap) {
                return ['ok' => false, 'stats' => $stats,
                        'why' => 'listing this project\'s records did not finish within the memory '
                               . 'this server allows for one request'];
            }

            $pg = $src->page($cursor, $carry, $pageSize);
            if (!$pg['ok']) {
                return ['ok' => false, 'stats' => $stats, 'why' => $pg['why']];
            }
            $stats['pages']++;
            $batch = [];
            foreach ($pg['rows'] as $row) {
                $stats['listed']++;
                if ($dag !== null && (string) $row['dag'] !== (string) $dag) {
                    $stats['outOfScope']++;
                    continue;
                }
                $batch[] = ['id_bin' => $row['id'],
                            'hash' => Hmac::raw(Hmac::P_RECORD, $pid, $row['id'], $this->key),
                            'dag' => $row['dag']];
            }
            if ($batch) {
                $stats['appended'] += (int) $this->store->appendManifest($runId, $batch);
            }
            $cursor = $pg['cursor'];
            $carry = $pg['emitted'];
            if ($pg['done']) break;
        }
        return ['ok' => true, 'stats' => $stats, 'why' => null];
    }

    private static function no($why)
    {
        return ['ok' => false, 'busy' => false, 'run' => null, 'why' => $why, 'stats' => []];
    }

    // -- canonical encoding --------------------------------------------------

    /**
     * A byte string that is the same for equal values and different for unequal
     * ones, with no dependency on character encoding.
     *
     * NOT json_encode, and this is the module's own hard-won reason. The L-01
     * finding recorded that record values can carry invalid UTF-8 from a Latin-1
     * import; json_encode returns FALSE on those, and the substitute flag
     * collapses every distinct invalid byte to U+FFFD — a lossy, data-
     * constructible collision, which is exactly the defect L-01 was. A
     * fingerprint built on it would report two different configurations as the
     * same one.
     *
     * Every value is tagged with its type before its contents, so 1, "1", 1.0
     * and true encode differently. PHP's array keys make that necessary rather
     * than fussy: $a["1"] and $a[1] are the same key, and without the tag a map
     * keyed by strings would collide with one keyed by integers.
     *
     * Strings are hex, which doubles the length and is worth it: the alternative
     * is a delimiter, and a delimiter is a thing a value can contain.
     */
    public static function canonical($v)
    {
        if ($v === null) return 'n';
        if (is_bool($v)) return $v ? 'b1' : 'b0';
        if (is_int($v)) return 'i' . $v;
        if (is_float($v)) {
            // NAN != NAN, so it has no canonical form as a number; INF has no
            // decimal one. Both are named rather than printed, because
            // sprintf's output for them varies by platform.
            if (is_nan($v)) return 'dNAN';
            if (is_infinite($v)) return ($v > 0 ? 'dINF' : 'd-INF');
            // 17 significant digits round-trips every IEEE 754 double.
            return 'd' . sprintf('%.17G', $v);
        }
        if (is_string($v)) return 's' . strlen($v) . ':' . bin2hex($v);
        if (is_array($v)) {
            if (self::isList($v)) {
                $out = 'l' . count($v) . ':';
                foreach ($v as $x) $out .= self::canonical($x) . ',';
                return $out . ';';
            }
            $keys = array_keys($v);
            // SORT_STRING, not the default: the default sort compares numeric
            // strings numerically, so ["10" => a, "9" => b] would order
            // differently from ["10" => a, "9" => b] read back as integers.
            // Byte order is the one comparison both PHP and this file agree on.
            usort($keys, function ($a, $b) {
                return strcmp((string) $a, (string) $b);
            });
            $out = 'm' . count($v) . ':';
            foreach ($keys as $k) {
                $out .= self::canonical($k) . '=' . self::canonical($v[$k]) . ',';
            }
            return $out . ';';
        }
        if (is_object($v)) {
            // An object in a fingerprint input is a caller mistake, and a silent
            // cast to array would hash its private state. Say so instead.
            throw new \InvalidArgumentException('canonical(): objects have no canonical form ('
                . get_class($v) . ')');
        }
        // Resources and anything else: refuse rather than guess.
        throw new \InvalidArgumentException('canonical(): unsupported type ' . gettype($v));
    }

    /** A PHP list: sequential integer keys from zero. */
    private static function isList(array $a)
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }

    // -- rule identity -------------------------------------------------------

    /**
     * The stable name and revision of one rule.
     *
     * @param array  $rule       as getRules() produced it
     * @param string $origin     'settings' | 'annotation'
     * @param int    $occurrence which identical sibling this is, zero-based
     * `stem` is the name WITHOUT the occurrence suffix. It is returned rather
     * than recovered by a caller, because recovering it means stripping a
     * trailing ":<digits>" and a persistent id is allowed to end in one.
     *
     * @return array{source_id:string, revision:string, origin:string, stem:string}
     */
    public static function identify(array $rule, $origin, $occurrence = 0)
    {
        $rev = self::revision($rule);
        $occ = (int) $occurrence;

        if ($origin === 'settings') {
            // A persistent id stored on the row is the right answer, because it
            // survives editing the rule - which is the one thing a content hash
            // cannot do. Every project today has none, so the fallback below is
            // what actually runs; it is content-derived and therefore stable
            // under the reordering that broke the ordinal.
            foreach (['uid', 'rule-uid', 'ruleUid'] as $k) {
                if (isset($rule[$k]) && is_string($rule[$k]) && $rule[$k] !== '') {
                    // An issued id is already unique; occurrence never applies
                    // to it, so the stem IS the name.
                    return ['source_id' => 'uid:' . $rule[$k], 'revision' => $rev,
                            'origin' => $origin, 'stem' => 'uid:' . $rule[$k]];
                }
            }
            $stem = 'set:' . substr($rev, 0, 32);
            return ['source_id' => $stem . ':' . $occ, 'revision' => $rev,
                    'origin' => $origin, 'stem' => $stem];
        }

        // An annotation rule is text living on fields, so its name is where it
        // lives. The field list is sorted: groupMulti builds it in dictionary
        // order, and moving a field in the Online Designer must not rename the
        // rule written on it.
        $fields = isset($rule['fields']) && is_array($rule['fields']) ? $rule['fields'] : [];
        $fields = array_map('strval', $fields);
        sort($fields, SORT_STRING);
        $where = substr(hash('sha256', self::canonical($fields)), 0, 32);
        $stem = 'ann:' . $where . ':' . self::family($rule);
        return ['source_id' => $stem . ':' . $occ, 'revision' => $rev,
                'origin' => $origin, 'stem' => $stem];
    }

    /**
     * A rule's content hash: what changing the rule changes.
     *
     * `fields` is excluded on purpose. Which fields a rule covers is part of
     * WHERE it applies, and every finding already records its own field; folding
     * it in would make adding one field to a rule read as a different rule, and
     * every finding on the untouched fields would be attributed to a rule that
     * no longer exists.
     */
    public static function revision(array $rule)
    {
        unset($rule['fields']);
        // Authoring metadata that exists to be read by a person: a renamed rule
        // is the same rule. Excluded so an administrator can improve a label
        // without invalidating a 100,000-record baseline.
        unset($rule['label'], $rule['note'], $rule['ruleNote'], $rule['message'],
              $rule['uid'], $rule['rule-uid'], $rule['ruleUid']);
        return hash('sha256', self::canonical($rule));
    }

    /**
     * Which tag family a rule belongs to.
     *
     * Derived from `type` rather than from the tag text, because the tag text is
     * not on the rule by the time it reaches here - groupMulti drops `_tag`.
     * Unknown types answer 'other' rather than throwing: a rule type this build
     * does not recognise still needs a name, and refusing to name it would make
     * a future type unscannable rather than merely unlabelled.
     */
    private static function family(array $rule)
    {
        $t = isset($rule['type']) ? (string) $rule['type'] : '';
        if ($t === '') return 'other';
        if (!preg_match('/^[a-z][a-z0-9_-]*\z/i', $t)) return 'other';
        return strtolower($t);
    }

    /**
     * Name a whole rule list at once, handling identical siblings.
     *
     * Occurrence is assigned within each (origin, name-without-occurrence)
     * group, in list order, so two rules that are identical in every respect
     * still get distinct names - and the SAME distinct names on the next run,
     * because the list order of identical siblings is itself derived from
     * content.
     *
     * @param array $rules   as getRules() produced them
     * @param int   $settingsCount how many leading entries came from settings
     * @return array parallel to $rules
     */
    public static function identifyAll(array $rules, $settingsCount)
    {
        $seen = [];
        $out = [];
        foreach (array_values($rules) as $i => $r) {
            $origin = ($i < (int) $settingsCount) ? 'settings' : 'annotation';
            // Probe with occurrence zero to learn the un-numbered part of the
            // name, then count how many of those we have already issued.
            $probe = self::identify($r, $origin, 0);
            $stem = $probe['stem'];
            $n = isset($seen[$stem]) ? $seen[$stem] : 0;
            $seen[$stem] = $n + 1;
            $out[] = self::identify($r, $origin, $n);
        }
        return $out;
    }

    // -- fingerprint ---------------------------------------------------------

    /**
     * One hash over everything that decides WHICH findings a scan produces.
     *
     * A run stores it; a resumed or incremental run compares it. A mismatch
     * means the configuration moved underneath the run, and the only safe answer
     * is a full pass — never a quiet continuation, which would produce a report
     * half of which was checked against rules that no longer exist.
     *
     * MISSING INPUTS THROW. A fingerprint that silently omitted, say, the
     * repeating-instrument structure would be a fingerprint that fails to notice
     * the change it exists to notice, and it would fail QUIETLY — the worst
     * available direction. Extra inputs are folded in rather than rejected,
     * because the safe direction for an unrecognised input is that it counts.
     */
    public static function fingerprint(array $spec)
    {
        foreach (self::$required as $k) {
            if (!array_key_exists($k, $spec)) {
                throw new \InvalidArgumentException('fingerprint(): missing input "' . $k
                    . '"; a fingerprint that omits an input cannot notice it changing');
            }
        }
        foreach (self::$forbidden as $k => $why) {
            if (array_key_exists($k, $spec)) {
                throw new \InvalidArgumentException('fingerprint(): "' . $k
                    . '" must not be an input - ' . $why);
            }
        }
        return hash('sha256', 'uv-scan-fp/1' . self::canonical($spec));
    }

    /**
     * Did the configuration move? Reported as a comparison rather than a bare
     * boolean so a caller can say WHICH way, and a report can say so too.
     */
    public static function fingerprintMatches($stored, $current)
    {
        if (!is_string($stored) || !is_string($current)) return false;
        if (strlen($stored) !== 64 || strlen($current) !== 64) return false;
        // Length-constant, because the fingerprint is derived from project
        // configuration a survey respondent can never see and an unprivileged
        // user should not be able to probe by timing a resume.
        return hash_equals($stored, $current);
    }
}
