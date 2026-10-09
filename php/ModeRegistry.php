<?php
/**
 * ModeRegistry — the validation modes, read from php/modes.json.
 *
 * Each action tag configures one MODE (check, constraint, required, unique,
 * choices, ...). Before this class every place that cared about modes kept
 * its own list: the tag map, the field-type gates, the branch keys, the
 * condition keys, the client keys, the dispatcher. A new mode had to be added
 * to about a dozen of them by hand, and the ones that were missed failed
 * quietly. Now the list lives in one JSON file; this class serves it to PHP and
 * tests/gen_mode_registry.cjs writes the browser's copy into js/engine.js.
 *
 * A missing or malformed file throws. getRules() does not memoize a failure, so
 * the next request tries again, and a page never runs with a partial registry.
 *
 * Pure static class with no REDCap dependency; tests/registry_php.php pins it
 * against frozen copies of the maps it replaced.
 */

namespace INSPIRE\UniversalValidator;

require_once __DIR__ . '/Logic.php';

final class ModeRegistry
{
    /** @var array|null the decoded, checked file */
    private static $data = null;
    /** @var array derived maps, built once per request */
    private static $memo = [];
    /** @var string|null test override for the file path */
    private static $path = null;

    /** Point the registry at another file (tests only). null restores the default. */
    public static function useFile($path)
    {
        self::$path = $path;
        self::$data = null;
        self::$memo = [];
    }

    /** The decoded file. Throws \RuntimeException on a missing or malformed file. */
    private static function data()
    {
        if (self::$data !== null) return self::$data;
        $file = self::$path !== null ? self::$path : __DIR__ . '/modes.json';
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('mode registry ' . basename($file) . ' could not be read');
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['modes']) || !is_array($j['modes']) || !$j['modes']) {
            throw new \RuntimeException('mode registry ' . basename($file) . ' is not valid JSON with a "modes" list');
        }
        $byMode = [];
        $seenTypes = [];
        $seenTags = [];
        foreach ($j['modes'] as $i => $m) {
            foreach (['mode', 'tag', 'defaultType', 'parser', 'evaluator'] as $k) {
                if (!isset($m[$k]) || !is_string($m[$k]) || $m[$k] === '') {
                    throw new \RuntimeException('mode registry entry ' . ($i + 1) . ' has no "' . $k . '"');
                }
            }
            foreach (['types', 'branchKeys', 'clientKeys', 'refKeys', 'needs'] as $k) {
                if (!isset($m[$k]) || !is_array($m[$k])) {
                    throw new \RuntimeException('mode "' . $m['mode'] . '" has no "' . $k . '" list');
                }
            }
            if (isset($m['serverKeys']) && !is_array($m['serverKeys'])) {
                throw new \RuntimeException('mode "' . $m['mode'] . '" has a serverKeys that is not a list');
            }
            if (!isset($m['eligibility']['fieldTypes']) || !is_array($m['eligibility']['fieldTypes'])) {
                throw new \RuntimeException('mode "' . $m['mode'] . '" has no eligibility.fieldTypes');
            }
            if (isset($byMode[$m['mode']])) throw new \RuntimeException('mode "' . $m['mode'] . '" is listed twice');
            if (isset($seenTags[strtolower($m['tag'])])) throw new \RuntimeException('tag ' . $m['tag'] . ' is listed twice');
            if (!in_array($m['defaultType'], $m['types'], true)) {
                throw new \RuntimeException('mode "' . $m['mode'] . '" default type is not one of its types');
            }
            foreach ($m['types'] as $t) {
                if (isset($seenTypes[$t])) throw new \RuntimeException('type "' . $t . '" belongs to two modes');
                $seenTypes[$t] = true;
            }
            foreach ($m['refKeys'] as $rk) {
                if (!isset($rk['key'], $rk['kind']) || !in_array($rk['kind'], ['cond', 'fieldList', 'operand'], true)
                    || ($rk['kind'] === 'operand' && (!isset($rk['op'], $rk['value'])
                        || !is_string($rk['op']) || !is_string($rk['value'])))) {
                    throw new \RuntimeException('mode "' . $m['mode'] . '" has a malformed refKeys entry');
                }
            }
            $seenTags[strtolower($m['tag'])] = true;
            $byMode[$m['mode']] = $m;
        }
        if (!isset($byMode['check'])) throw new \RuntimeException('mode registry has no "check" mode');
        $j['byMode'] = $byMode;
        if (!isset($j['common']['refKeys']) || !is_array($j['common']['refKeys'])) $j['common']['refKeys'] = [];
        self::$data = $j;
        return self::$data;
    }

    private static function memo($key, callable $build)
    {
        if (!array_key_exists($key, self::$memo)) self::$memo[$key] = $build();
        return self::$memo[$key];
    }

    /** Every mode entry, in file order. */
    public static function all()
    {
        return self::data()['modes'];
    }

    /** One mode's entry; throws for an unknown mode name. */
    public static function mode($mode)
    {
        $d = self::data();
        if (!isset($d['byMode'][$mode])) throw new \InvalidArgumentException('unknown mode "' . $mode . '"');
        return $d['byMode'][$mode];
    }

    /**
     * The validation MODE a rule's "type" belongs to. Unknown, empty and
     * missing types are the check mode, which then refuses a type it does not
     * know ('"type" must be "single" or "pooled"').
     */
    public static function modeOfType($type)
    {
        $map = self::memo('typeMode', function () {
            $out = [];
            foreach (self::all() as $m) foreach ($m['types'] as $t) $out[$t] = $m['mode'];
            return $out;
        });
        return (is_string($type) && isset($map[$type])) ? $map[$type] : 'check';
    }

    /** The types of one mode. */
    public static function types($mode)
    {
        return self::mode($mode)['types'];
    }

    /** tag => mode, in file order (the order annotations are read). */
    public static function tagMap()
    {
        return self::memo('tagMap', function () {
            $out = [];
            foreach (self::all() as $m) $out[$m['tag']] = $m['mode'];
            return $out;
        });
    }

    /** The action tag of a mode. */
    public static function tag($mode)
    {
        return self::mode($mode)['tag'];
    }

    /** The action tag a rule or fragment of this type came from. */
    public static function tagOfType($type)
    {
        return self::tag(self::modeOfType($type));
    }

    /**
     * Per-rule keys a branch inherits from its source rule: the union of every
     * mode's branchKeys, in file order.
     */
    public static function branchKeys()
    {
        return self::memo('branchKeys', function () {
            $out = [];
            foreach (self::all() as $m) foreach ($m['branchKeys'] as $k) $out[$k] = true;
            return array_keys($out);
        });
    }

    /** Keys the browser engine copies from a rule: the union of every mode's clientKeys. */
    public static function clientKeys()
    {
        return self::memo('clientKeys', function () {
            $out = [];
            foreach (self::all() as $m) foreach ($m['clientKeys'] as $k) $out[$k] = true;
            return array_keys($out);
        });
    }

    /**
     * Keys the server reads and the page must never carry (the union of every
     * mode's optional serverKeys): where an @UVEXISTS lookup searches is
     * re-read from the stored rule by the endpoint, so the page has no use for
     * it and a survey respondent has no business seeing it.
     */
    public static function serverKeys()
    {
        return self::memo('serverKeys', function () {
            $out = [];
            foreach (self::all() as $m) {
                foreach ((isset($m['serverKeys']) && is_array($m['serverKeys'])) ? $m['serverKeys'] : [] as $k) $out[$k] = true;
            }
            return array_keys($out);
        });
    }

    /** A rule as the page may see it: every server key removed, on the rule and on each branch. */
    public static function clientShape(array $r)
    {
        foreach (self::serverKeys() as $k) unset($r[$k]);
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $i => $b) {
                if (!is_array($b)) continue;
                foreach (self::serverKeys() as $k) unset($r['branches'][$i][$k]);
            }
        }
        return $r;
    }

    /** Field-type eligibility of a mode: fieldTypes plus the refusal wording. */
    public static function eligibility($mode)
    {
        return self::mode($mode)['eligibility'];
    }

    /**
     * The annotation-channel refusal for a field type the mode does not
     * support, or null when the type is eligible.
     */
    public static function ineligibleWhy($mode, $fieldType)
    {
        $e = self::eligibility($mode);
        if (in_array($fieldType, $e['fieldTypes'], true)) return null;
        if (isset($e['annotationWhyFor'][$fieldType])) return $e['annotationWhyFor'][$fieldType];
        return strtr($e['annotationWhy'], ['{type}' => (string) $fieldType]);
    }

    /** A per-mode method name from the "hooks" block, or null. */
    public static function hook($mode, $phase)
    {
        $m = self::mode($mode);
        return (isset($m['hooks'][$phase]) && is_string($m['hooks'][$phase])) ? $m['hooks'][$phase] : null;
    }

    /** Whether the mode needs a runtime service ('transport', 'clock'). */
    public static function needs($mode, $what)
    {
        return in_array($what, self::mode($mode)['needs'], true);
    }

    /** Whether any live (non-config-error) rule in the list needs $what. */
    public static function rulesNeed(array $rules, $what)
    {
        foreach ($rules as $r) {
            if (!is_array($r) || !empty($r['configError'])) continue;
            if (self::needs(self::modeOfType(isset($r['type']) ? $r['type'] : ''), $what)) return true;
        }
        return false;
    }

    /** The findings method of a mode (a UniversalValidator method name). */
    public static function evaluator($mode)
    {
        return self::mode($mode)['evaluator'];
    }

    /** Whether a mode's rules carry a check-character algorithm. */
    public static function hasAlgorithm($mode)
    {
        return !empty(self::mode($mode)['hasAlgorithm']);
    }

    /** Whether the module's Configure dialog can set up rules of this mode. */
    public static function inDialog($mode)
    {
        return !empty(self::mode($mode)['dialog']);
    }

    /** The scan report's "Issue" label for a finding of this type and reason. */
    public static function issueLabel($type, $reason = '')
    {
        $labels = self::mode(self::modeOfType($type))['scan']['issueLabels'] ?? [];
        $reason = (string) $reason;
        if ($reason !== '' && isset($labels[$reason])) return $labels[$reason];
        return isset($labels['*']) ? $labels['*'] : 'Wrong value';
    }

    /**
     * Rule keys the scan report copies into its rule snapshot, for the
     * catalog's "detail" sentence (scan.detailKeys of the type's mode).
     */
    public static function detailKeys($type)
    {
        $scan = self::mode(self::modeOfType($type))['scan'] ?? [];
        return (isset($scan['detailKeys']) && is_array($scan['detailKeys'])) ? $scan['detailKeys'] : [];
    }

    /**
     * Every refKeys entry across common and all modes, de-duplicated by key,
     * common first. Optionally only one kind and/or role.
     */
    public static function refKeys($kind = null, $role = null)
    {
        $all = self::memo('refKeys', function () {
            $out = [];
            foreach (self::data()['common']['refKeys'] as $rk) $out[$rk['key']] = $rk;
            foreach (self::all() as $m) foreach ($m['refKeys'] as $rk) {
                if (!isset($out[$rk['key']])) $out[$rk['key']] = $rk;
            }
            return array_values($out);
        });
        $res = [];
        foreach ($all as $rk) {
            if ($kind !== null && $rk['kind'] !== $kind) continue;
            if ($role !== null && (isset($rk['role']) ? $rk['role'] : null) !== $role) continue;
            $res[] = $rk;
        }
        return $res;
    }

    /** Condition key names ('when', 'assert', ...), gate first. */
    public static function condKeys($role = null)
    {
        $out = [];
        foreach (self::refKeys('cond', $role) as $rk) $out[] = $rk['key'];
        return $out;
    }

    /**
     * Every non-empty condition string a rule carries (its own, and its
     * branches'), for the condition keys of $role (null = every role).
     */
    public static function conditionTexts(array $r, $role = null)
    {
        $keys = self::condKeys($role);
        $out = [];
        foreach ($keys as $k) {
            if (isset($r[$k]) && is_string($r[$k]) && $r[$k] !== '') $out[] = $r[$k];
        }
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) {
                if (!is_array($b)) continue;
                foreach ($keys as $k) {
                    if (isset($b[$k]) && is_string($b[$k]) && $b[$k] !== '') $out[] = $b[$k];
                }
            }
        }
        return $out;
    }

    /**
     * Every field named in a fieldList key ("with" of @UVUNIQUE, ...), on the
     * rule and on its branches.
     */
    public static function fieldListRefs(array $r)
    {
        $out = [];
        $nodes = [$r];
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) if (is_array($b)) $nodes[] = $b;
        }
        foreach (self::refKeys('fieldList') as $rk) {
            foreach ($nodes as $n) {
                if (!isset($n[$rk['key']]) || !is_array($n[$rk['key']])) continue;
                foreach ($n[$rk['key']] as $w) { if (is_string($w) && $w !== '') $out[] = $w; }
            }
        }
        return $out;
    }

    /**
     * The operand keys ("from" of @UVWINDOW, ...): one field reference whose
     * VALUE the verdict reads, as opposed to a condition. Each entry names the
     * key the browser receives the folded operand under ("op") and the key the
     * server's extended compile writes the resolved value to ("value").
     */
    public static function operandKeys()
    {
        return self::refKeys('operand');
    }

    /**
     * The one reference an operand string names, as a parsed operand node
     * (['ref', field, null], or with $opts['qualified'] also ['qref', ...]),
     * or null when the text is not exactly one scalar field reference. A
     * checkbox code, a binding, a collection and anything with an operator are
     * all null.
     */
    public static function operandRef($text, array $opts = [])
    {
        if (!is_string($text) || trim($text) === '') return null;
        $p = Logic::parse($text . "=''", $opts);
        if (empty($p['ok'])) return null;
        $ast = $p['ast'];
        if ($ast[0] !== 'cmp' || $ast[1] !== '=' || $ast[3] !== ['lit', '']) return null;
        $op = $ast[2];
        if ($op[0] === 'ref') return $op[2] === null ? $op : null;
        if ($op[0] === 'qref') {
            if ($op[2] !== null || in_array($op[4], ['any-instance', 'all-instances'], true)) return null;
            return $op;
        }
        return null;
    }

    /** Every non-empty operand string a rule carries: its own, and its branches'. */
    public static function operandTexts(array $r)
    {
        $out = [];
        $nodes = [$r];
        if (isset($r['branches']) && is_array($r['branches'])) {
            foreach ($r['branches'] as $b) if (is_array($b)) $nodes[] = $b;
        }
        foreach (self::operandKeys() as $rk) {
            foreach ($nodes as $n) {
                if (isset($n[$rk['key']]) && is_string($n[$rk['key']]) && $n[$rk['key']] !== '') $out[] = $n[$rk['key']];
            }
        }
        return $out;
    }

    /**
     * Every field a rule's own configuration reads besides its validated
     * fields: the operands of all its conditions, its operand keys and its
     * field lists. Strings that do not parse contribute nothing (they are
     * config errors already). $kinds narrows the sources: the save hook's
     * reverse dependencies follow conditions and operands only.
     *
     * @return string[] field names, de-duplicated, in discovery order
     */
    public static function refFields(array $r, array $kinds = ['cond', 'operand', 'fieldList'])
    {
        $out = [];
        if (in_array('cond', $kinds, true)) {
            foreach (self::conditionTexts($r) as $cond) {
                $p = Logic::parse($cond);
                if (empty($p['ok'])) continue;
                foreach (Logic::referencedFields($p['ast']) as $ref) $out[(string) $ref[0]] = true;
            }
        }
        if (in_array('operand', $kinds, true)) {
            foreach (self::operandTexts($r) as $text) {
                $op = self::operandRef($text);
                if ($op !== null) $out[(string) $op[1]] = true;
            }
        }
        if (in_array('fieldList', $kinds, true)) {
            foreach (self::fieldListRefs($r) as $w) $out[(string) $w] = true;
        }
        return array_keys($out);
    }
}
