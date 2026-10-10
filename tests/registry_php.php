<?php
/**
 * registry_php.php — the mode registry (php/modes.json, php/ModeRegistry.php).
 *
 * Three groups of checks:
 *
 *   1. FROZEN MAPS. The registry replaced a dozen hand-kept lists. Each of
 *      them is copied here as it stood before (v2.1.0-rc.2), and the registry
 *      must reproduce it exactly, order included where order mattered. A
 *      change to these is a behaviour change and has to be made on purpose.
 *   2. COVERAGE. A few places stay hand-written on purpose (config.json
 *      action-tags, the site's page list, the message catalog, the engine's
 *      public namespace and factory table, the per-mode test samples). Every
 *      mode in the registry must appear in each, so a new mode cannot ship
 *      half-wired.
 *   3. FAILURE. A missing or malformed registry throws instead of running
 *      with a partial list.
 *
 * Run:  php tests/registry_php.php
 */

require_once __DIR__ . '/../php/ModeRegistry.php';
require_once __DIR__ . '/../php/AnnotationRules.php';
require_once __DIR__ . '/../php/Branching.php';

use INSPIRE\UniversalValidator\ModeRegistry;
use INSPIRE\UniversalValidator\AnnotationRules;
use INSPIRE\UniversalValidator\Branching;

$n = 0;
$fails = 0;
function check($label, $ok)
{
    global $n, $fails;
    $n++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}

$root = dirname(__DIR__);

// ---- 1. frozen maps ---------------------------------------------------------

// AnnotationRules::TAGS (tag => mode), in reading order.
// Modes added since then are appended, so the old entries keep their order.
check('tag map equals the old AnnotationRules::TAGS, in order, then the new tags', ModeRegistry::tagMap() === [
    '@UVALIDATE'  => 'check',
    '@UVASSERT'   => 'constraint',
    '@UVREQUIRED' => 'required',
    '@UVUNIQUE'   => 'unique',
    '@UVCHOICES'  => 'choices',
    '@UVWINDOW'   => 'window',      // 2.2.0
    '@UVEXISTS'   => 'exists',      // 2.3.0
    '@UVRANGE'    => 'range',       // 2.5.0
]);
foreach (['TAG' => 'check', 'TAG_ASSERT' => 'constraint', 'TAG_REQUIRED' => 'required',
          'TAG_UNIQUE' => 'unique', 'TAG_CHOICES' => 'choices', 'TAG_WINDOW' => 'window',
          'TAG_EXISTS' => 'exists', 'TAG_RANGE' => 'range'] as $const => $mode) {
    check('AnnotationRules::' . $const . ' is the ' . $mode . ' tag',
        constant(AnnotationRules::class . '::' . $const) === ModeRegistry::tag($mode));
}

// Branching::BRANCH_KEYS, in order (branchOf copies in this order).
check('branch keys equal the old Branching::BRANCH_KEYS, in order, then the new modes keys', ModeRegistry::branchKeys() === [
    'algorithm', 'idPattern', 'alternates', 'source', 'strip', 'keepChars',
    'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds',
    'blockSave', 'suggestFix', 'note',
    'assert', 'caseSensitive', 'message', 'references',
    'uniqueWith', 'uniqueScope', 'uniqueSurveys',
    'choicesShow', 'choicesHide', 'choicesAll',
    // 2.2.0 @UVWINDOW
    'windowFrom', 'windowLo', 'windowHi', 'windowUnit', 'windowNotFuture',
    'dateType', 'dateFormat', 'fromType', 'fromFormat',
    // 2.3.0 @UVEXISTS
    'existsIn', 'existsEvent', 'existsScope', 'existsMatch', 'existsSurveys', 'existsLocal', 'existsTargets',
    // 2.4.0 @UVEXISTS in another project
    'existsProject', 'existsPid', 'existsRemoteTargets',
    // 2.5.0 @UVRANGE
    'rangeSoftLo', 'rangeSoftHi', 'rangeHardLo', 'rangeHardHi', 'rangeSoftBlock', 'rangeHardBlock',
    'rangeUnit', 'rangeSoftText', 'rangeHardText', 'decimalComma', 'rangeComputed',
    // 2.6.0 @UVRANGE growth references
    'rangeReference', 'rangeSex', 'rangeMale', 'rangeFemale', 'rangeAgeDob', 'rangeAgeAt',
    'rangeAgeDays', 'rangeAgeMonths', 'rangeBy', 'rangeDobType', 'rangeDobFormat',
    'rangeAtType', 'rangeAtFormat', 'rangeAxisComma',
]);

// The engine's DEFAULT_KEYS, in order.
check('client keys equal the old engine DEFAULT_KEYS, in order, then the new modes keys', ModeRegistry::clientKeys() === [
    'algorithm', 'idPattern', 'alternates', 'source', 'strip', 'suggestFix',
    'keepChars', 'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds',
    'blockSave', 'when', 'whenAst',
    'assert', 'assertAst', 'caseSensitive', 'message', 'deferred', 'deferredWhy', 'snapshotFields',
    'uniqueWith', 'uniqueScope', 'uniqueSurveys', 'uniqueRecordAsts',
    'choicesShow', 'choicesHide', 'choicesAll',
    // 2.2.0 @UVWINDOW
    'windowFrom', 'windowFromOp', 'windowFromOpWhy', 'windowLo', 'windowHi', 'windowUnit', 'windowNotFuture',
    'dateType', 'dateFormat', 'fromType', 'fromFormat',
    // a condition read when the page opened; notFuture dropped for its time zone
    'snapshotGate', 'windowNotFutureOff',
    // 2.3.0 @UVEXISTS (where it looks stays on the server)
    'existsLocal', 'existsSurveys',
    // 2.5.0 @UVRANGE
    'rangeSoftLo', 'rangeSoftHi', 'rangeHardLo', 'rangeHardHi', 'rangeSoftBlock', 'rangeHardBlock',
    'rangeUnit', 'rangeSoftText', 'rangeHardText', 'decimalComma', 'rangeComputed',
    // 2.6.0 @UVRANGE growth references (the inputs travel folded, as ops;
    // deferredOnSave marks a rule the page could not carry but the save checks)
    'rangeReference', 'rangeSexOp', 'rangeMale', 'rangeFemale', 'rangeAgeDobOp', 'rangeAgeAtOp',
    'rangeAgeDaysOp', 'rangeAgeMonthsOp', 'rangeByOp', 'rangeDobType', 'rangeDobFormat',
    'rangeAtType', 'rangeAtFormat', 'rangeAxisComma', 'deferredOnSave', 'extendedAdvisory',
]);

// Branching::modeOfType.
foreach (['constraint' => 'constraint', 'required' => 'required', 'unique' => 'unique',
          'choices' => 'choices', 'single' => 'check', 'pooled' => 'check', '' => 'check',
          'nonsense' => 'check', 'constructor' => 'check', '__proto__' => 'check'] as $t => $want) {
    check('modeOfType(' . json_encode($t) . ') = ' . $want, ModeRegistry::modeOfType($t) === $want
        && Branching::modeOfType($t) === $want);
}
check('modeOfType(null) = check', ModeRegistry::modeOfType(null) === 'check');
check('modeOfType of an array = check', ModeRegistry::modeOfType(['x']) === 'check');

// AnnotationRules::*_FIELD_TYPES and the check mode's text/notes gate.
check('check field types', ModeRegistry::eligibility('check')['fieldTypes'] === ['text', 'notes']);
check('constraint field types (old CONSTRAINT_FIELD_TYPES)', ModeRegistry::eligibility('constraint')['fieldTypes']
    === ['text', 'notes', 'dropdown', 'radio', 'yesno', 'truefalse', 'calc', 'sql', 'slider']);
check('required field types (old REQUIRED_FIELD_TYPES)', ModeRegistry::eligibility('required')['fieldTypes']
    === ['text', 'notes', 'dropdown', 'radio', 'yesno', 'truefalse', 'sql', 'slider']);
check('unique field types (old UNIQUE_FIELD_TYPES)', ModeRegistry::eligibility('unique')['fieldTypes']
    === ['text', 'notes', 'dropdown', 'radio', 'yesno', 'truefalse', 'sql', 'slider']);
check('choices field types (old CHOICES_FIELD_TYPES)', ModeRegistry::eligibility('choices')['fieldTypes']
    === ['radio', 'dropdown', 'checkbox']);

// The annotation channel's refusal wording, byte for byte.
check('check refusal wording', ModeRegistry::ineligibleWhy('check', 'radio')
    === 'this tag only works on Text or Notes fields (this field is "radio").');
check('constraint refusal wording', ModeRegistry::ineligibleWhy('constraint', 'file')
    === '@UVASSERT does not support "file" fields — it checks one scalar field\'s value against a condition.');
check('required refusal wording', ModeRegistry::ineligibleWhy('required', 'file')
    === '@UVREQUIRED does not support "file" fields — it requires a scalar input the person can fill in.');
check('required refusal wording for calc', ModeRegistry::ineligibleWhy('required', 'calc')
    === '@UVREQUIRED does not support "calc" fields — a calc value is computed, the person entering data cannot fill it in.');
check('unique refusal wording', ModeRegistry::ineligibleWhy('unique', 'checkbox')
    === '@UVUNIQUE does not support "checkbox" fields — it compares one scalar field\'s value across records.');
check('choices refusal wording', ModeRegistry::ineligibleWhy('choices', 'text')
    === '@UVCHOICES does not support "text" fields — it filters the options of a radio, dropdown or checkbox field.');
check('an eligible type has no refusal', ModeRegistry::ineligibleWhy('constraint', 'calc') === null);
check('a type value cannot inject a second placeholder pass',
    ModeRegistry::ineligibleWhy('check', '{type}') === 'this tag only works on Text or Notes fields (this field is "{type}").');

// The settings dialog's wording.
check('dialog wording, check', ModeRegistry::eligibility('check')['dialogWhy'] === 'only Text and Notes fields can be validated');
check('dialog wording, constraint', ModeRegistry::eligibility('constraint')['dialogWhy']
    === 'a Constraint rule supports Text, Notes, dropdown, radio, yes/no, true/false, calc and slider fields');
check('dialog wording, required', ModeRegistry::eligibility('required')['dialogWhy']
    === 'a Required rule supports Text, Notes, dropdown, radio, yes/no, true/false and slider fields (not calc — the person entering data cannot fill it)');
check('dialog wording, unique', ModeRegistry::eligibility('unique')['dialogWhy']
    === 'a Unique rule supports Text, Notes, dropdown, radio, yes/no, true/false and slider fields (not calc — the person entering data cannot fix a calc collision)');

// The ruleFindings algorithm gate: only the check mode carries an algorithm.
foreach (['check' => true, 'constraint' => false, 'required' => false, 'unique' => false, 'choices' => false] as $m => $want) {
    check('hasAlgorithm(' . $m . ')', ModeRegistry::hasAlgorithm($m) === $want);
}
// Which modes the Configure dialog has boxes for.
foreach (['check' => true, 'constraint' => true, 'required' => true, 'unique' => true, 'choices' => false] as $m => $want) {
    check('inDialog(' . $m . ')', ModeRegistry::inDialog($m) === $want);
}
// The old hasUniqueRules(): only unique rules need the AJAX transport.
check('unique needs transport', ModeRegistry::needs('unique', 'transport'));
check('no other mode needs transport', !ModeRegistry::needs('check', 'transport') && !ModeRegistry::needs('choices', 'transport'));
check('rulesNeed ignores config-error rules', !ModeRegistry::rulesNeed([['type' => 'unique', 'configError' => 'x']], 'transport'));
check('rulesNeed finds a live unique rule', ModeRegistry::rulesNeed([['type' => 'single'], ['type' => 'unique']], 'transport'));

// ScanColumns' old issue map.
foreach (['required' => 'Missing value', 'unique' => 'Duplicate value', 'choices' => 'No longer an allowed choice',
          'constraint' => 'Wrong value', 'single' => 'Wrong value', 'pooled' => 'Wrong value', '' => 'Wrong value',
          'nonsense' => 'Wrong value'] as $t => $want) {
    check('issueLabel(' . json_encode($t) . ')', ModeRegistry::issueLabel($t, 'any-reason') === $want);
}

// Condition keys: the six ['when','assert'] lists of TemporalRules, and
// ruleWhens()/ruleAsserts()/ruleUniqueWith() of the module.
check('condition keys, gate first', ModeRegistry::condKeys() === ['when', 'assert']);
check('test condition keys', ModeRegistry::condKeys('test') === ['assert']);
check('gate condition keys', ModeRegistry::condKeys('gate') === ['when']);
$r = ['type' => 'constraint', 'fields' => ['f'], 'when' => "[g]='1'", 'assert' => '[f]>[h]',
      'branches' => [['when' => "[k]='2'", 'assert' => '[f]<[m]'], ['when' => null, 'uniqueWith' => ['w2']]],
      'uniqueWith' => ['w1', '', 5]];
check('conditionTexts: own then branches, every role', ModeRegistry::conditionTexts($r)
    === ["[g]='1'", '[f]>[h]', "[k]='2'", '[f]<[m]']);
check('conditionTexts: gate only', ModeRegistry::conditionTexts($r, 'gate') === ["[g]='1'", "[k]='2'"]);
check('conditionTexts: test only', ModeRegistry::conditionTexts($r, 'test') === ['[f]>[h]', '[f]<[m]']);
check('fieldListRefs: own and branches, strings only', ModeRegistry::fieldListRefs($r) === ['w1', 'w2']);
check('refFields: every operand and field list, once', ModeRegistry::refFields($r)
    === ['g', 'f', 'h', 'k', 'm', 'w1', 'w2']);
check('refFields skips a condition that does not parse',
    ModeRegistry::refFields(['when' => '[a]=', 'assert' => '[b]=1']) === ['b']);

// Operand keys (2.2.0): one field reference whose VALUE the verdict reads.
check('operand keys', array_map(function ($rk) { return [$rk['key'], $rk['op'], $rk['value']]; }, ModeRegistry::operandKeys())
    === [['windowFrom', 'windowFromOp', 'windowFromValue'],
         ['rangeSex', 'rangeSexOp', 'rangeSexValue'], ['rangeAgeDob', 'rangeAgeDobOp', 'rangeAgeDobValue'],
         ['rangeAgeAt', 'rangeAgeAtOp', 'rangeAgeAtValue'], ['rangeAgeDays', 'rangeAgeDaysOp', 'rangeAgeDaysValue'],
         ['rangeAgeMonths', 'rangeAgeMonthsOp', 'rangeAgeMonthsValue'], ['rangeBy', 'rangeByOp', 'rangeByValue']]);
check('an operand is not a condition key', !in_array('windowFrom', ModeRegistry::condKeys(), true));
foreach ([
    '[visit_date]' => ['ref', 'visit_date', null],
    ' [visit_date] ' => ['ref', 'visit_date', null],
    '[v(1)]' => null,                       // a checkbox code is not a date
    "[v]='x'" => null,
    '[v] or [w]' => null,
    "'2026-01-01'" => null,
    '' => null,
    '[ev_arm_1][v]' => null,               // qualified needs the option
] as $text => $want) {
    check('operandRef(' . json_encode($text) . ')', ModeRegistry::operandRef($text) === $want);
}
check('operandRef of a non-string', ModeRegistry::operandRef(['[v]']) === null && ModeRegistry::operandRef(null) === null);
check('operandRef qualified: another event', ModeRegistry::operandRef('[ev_arm_1][v]', ['qualified' => true])
    === ['qref', 'v', null, 'ev_arm_1', null]);
check('operandRef qualified: a list of instances is refused',
    ModeRegistry::operandRef('[v][any-instance]', ['qualified' => true]) === null
    && ModeRegistry::operandRef('[ev_arm_1][v][all-instances]', ['qualified' => true]) === null);
check('operandRef qualified: a binding is refused', ModeRegistry::operandRef('{b}', ['qualified' => true]) === null);
$w = ['type' => 'window', 'fields' => ['f'], 'when' => "[g]='1'", 'windowFrom' => '[a]',
      'branches' => [['when' => "[k]='2'", 'windowFrom' => '[b]'], ['windowFrom' => '[v(1)]']]];
check('operandTexts: own then branches', ModeRegistry::operandTexts($w) === ['[a]', '[b]', '[v(1)]']);
check('refFields follows operands', ModeRegistry::refFields($w) === ['g', 'k', 'a', 'b']);
check('refFields narrowed to conditions', ModeRegistry::refFields($w, ['cond']) === ['g', 'k']);
check('refFields narrowed to operands', ModeRegistry::refFields($w, ['operand']) === ['a', 'b']);
check('refFields narrowed to conditions and operands leaves field lists out',
    ModeRegistry::refFields($r, ['cond', 'operand']) === ['g', 'f', 'h', 'k', 'm']);

// The window mode (2.2.0).
check('window type is the window mode', ModeRegistry::modeOfType('window') === 'window');
check('window field types', ModeRegistry::eligibility('window')['fieldTypes'] === ['text']);
check('window needs the clock, not the transport', ModeRegistry::needs('window', 'clock') && !ModeRegistry::needs('window', 'transport'));
check('only window needs the clock', !ModeRegistry::needs('constraint', 'clock') && !ModeRegistry::needs('unique', 'clock'));
check('window has no algorithm', !ModeRegistry::hasAlgorithm('window'));
check('window is annotation-only', !ModeRegistry::inDialog('window'));
check('window issue label: future', ModeRegistry::issueLabel('window', 'future') === 'Date in the future');
check('window issue label: outside the window', ModeRegistry::issueLabel('window', 'window-early') === 'Date outside allowed window'
    && ModeRegistry::issueLabel('window', 'window-late') === 'Date outside allowed window');
check('window refusal wording', ModeRegistry::ineligibleWhy('window', 'notes')
    === '@UVWINDOW does not support "notes" fields — it checks a date typed into a Text field with date or datetime validation.');

// The range mode (2.5.0).
check('range type is the range mode', ModeRegistry::modeOfType('range') === 'range');
check('range field types', ModeRegistry::eligibility('range')['fieldTypes'] === ['text', 'calc', 'slider']);
check('range needs neither clock nor transport', !ModeRegistry::needs('range', 'clock') && !ModeRegistry::needs('range', 'transport'));
check('range has no algorithm', !ModeRegistry::hasAlgorithm('range'));
check('range is annotation-only', !ModeRegistry::inDialog('range'));
check('range issue labels', ModeRegistry::issueLabel('range', 'soft-low') === 'Unusual value'
    && ModeRegistry::issueLabel('range', 'soft-high') === 'Unusual value'
    && ModeRegistry::issueLabel('range', 'hard-low') === 'Implausible value'
    && ModeRegistry::issueLabel('range', 'hard-high') === 'Implausible value'
    && ModeRegistry::issueLabel('range', 'not-a-number') === 'Not a number');
check('range detail keys', ModeRegistry::detailKeys('range') === ['rangeSoftText', 'rangeHardText']);
$tv = ModeRegistry::eligibility('range')['textValidations'];
foreach (['' => true, 'integer' => true, 'int' => true, 'float' => true, 'number' => true, 'number_2dp' => true,
          'number_comma_decimal' => true, 'number_1dp_comma_decimal' => true, 'date_ymd' => false, 'email' => false,
          'number_10dp' => false, 'integer_comma_decimal' => false, 'xnumber' => false, 'numberx' => false] as $v => $ok) {
    check('range text validation ' . json_encode($v) . ($ok ? ' allowed' : ' refused'), (bool) preg_match('~' . $tv . '~', $v) === $ok);
}
check('range refusal wording', ModeRegistry::ineligibleWhy('range', 'notes')
    === '@UVRANGE does not support "notes" fields — it checks a number typed into a Text field, a calc or a slider.');

// ---- 2. coverage ------------------------------------------------------------

$config = json_decode(file_get_contents($root . '/config.json'), true);
$configTags = [];
foreach ($config['action-tags'] as $t) $configTags[] = $t['tag'];
$pages = file_get_contents($root . '/site/pages.py');
preg_match('/^TAG_SLUGS = \(([^)]*)\)/m', $pages, $m);
$slugs = $m ? array_map(function ($s) { return trim($s, " \t\r\n\"'"); }, explode(',', $m[1])) : [];
$catalog = json_decode(file_get_contents($root . '/php/messages/catalog.json'), true);
$engine = file_get_contents($root . '/js/engine.js');
$nsAt = strpos($engine, 'window.INSPIREUniversalValidator = {');
$namespace = $nsAt === false ? '' : substr($engine, $nsAt, strpos($engine, '};', $nsAt) - $nsAt);
$samples = json_decode(file_get_contents(__DIR__ . '/mode_samples.json'), true)['modes'];
$ann = new ReflectionClass(AnnotationRules::class);
$uvSource = file_get_contents($root . '/UniversalValidator.php');

foreach (ModeRegistry::all() as $mode) {
    $name = $mode['mode'];
    $tag = $mode['tag'];
    check($tag . ' is declared in config.json action-tags', in_array($tag, $configTags, true));
    check($tag . ' has a site page in site/pages.py TAG_SLUGS', in_array(strtolower(ltrim($tag, '@')), $slugs, true));
    check($tag . ' has a site content file', is_file($root . '/site/content/' . strtolower(ltrim($tag, '@')) . '.html'));
    check($name . ' has a sample in tests/mode_samples.json', isset($samples[$name]['tagForm'], $samples[$name]['fragment'], $samples[$name]['client']));
    check($name . ' parser AnnotationRules::' . $mode['parser'] . ' exists', $ann->hasMethod($mode['parser']));
    if ($mode['checker'] !== null) {
        check($name . ' checker AnnotationRules::' . $mode['checker'] . ' exists', $ann->hasMethod($mode['checker']));
    }
    check($name . ' evaluator ' . $mode['evaluator'] . ' exists in UniversalValidator',
        (bool) preg_match('/private function ' . preg_quote($mode['evaluator'], '/') . '\(/', $uvSource));
    foreach ($mode['hooks'] as $phase => $hook) {
        check($name . ' ' . $phase . ' hook ' . $hook . ' exists in UniversalValidator',
            in_array($phase, ['field', 'dictionary'], true)
            && (bool) preg_match('/private function ' . preg_quote($hook, '/') . '\(/', $uvSource));
    }
    foreach ($mode['types'] as $type) {
        $hasCatalog = false;
        foreach (array_keys($catalog) as $k) if (strpos($k, $type . '/') === 0) $hasCatalog = true;
        check('type ' . $type . ' has wording in php/messages/catalog.json', $hasCatalog);
        $factory = $mode['js']['factories'][$type] ?? '';
        check('type ' . $type . ' factory ' . $factory . ' is published in the engine namespace',
            $factory !== '' && (bool) preg_match('/:\s*' . preg_quote($factory, '/') . '\b/', $namespace));
    }
}
check('config.json declares no tag the registry lacks', array_diff($configTags, array_keys(ModeRegistry::tagMap())) === []);

// ---- 3. failure -------------------------------------------------------------

$tmp = sys_get_temp_dir() . '/uv_registry_' . getmypid() . '.json';
$good = json_decode(file_get_contents($root . '/php/modes.json'), true);
$cases = [
    'missing file'        => null,
    'not JSON'            => '{"modes": [',
    'no modes'            => json_encode(['modes' => []]),
    'type in two modes'   => (function ($g) { $g['modes'][1]['types'][] = 'single'; return json_encode($g); })($good),
    'tag twice'           => (function ($g) { $g['modes'][1]['tag'] = '@uvalidate'; return json_encode($g); })($good),
    'no check mode'       => (function ($g) { array_shift($g['modes']); return json_encode($g); })($good),
    'default not a type'  => (function ($g) { $g['modes'][2]['defaultType'] = 'x'; return json_encode($g); })($good),
    'bad refKeys kind'    => (function ($g) { $g['modes'][1]['refKeys'][0]['kind'] = 'operand?'; return json_encode($g); })($good),
    'operand without op'  => (function ($g) { $g['modes'][1]['refKeys'][0] = ['key' => 'x', 'kind' => 'operand', 'value' => 'xValue']; return json_encode($g); })($good),
    'operand without value' => (function ($g) { $g['modes'][1]['refKeys'][0] = ['key' => 'x', 'kind' => 'operand', 'op' => 'xOp']; return json_encode($g); })($good),
    'no field types'      => (function ($g) { unset($g['modes'][3]['eligibility']['fieldTypes']); return json_encode($g); })($good),
];
foreach ($cases as $label => $content) {
    @unlink($tmp);
    if ($content !== null) file_put_contents($tmp, $content);
    ModeRegistry::useFile($tmp);
    $threw = false;
    try { ModeRegistry::modeOfType('single'); } catch (\RuntimeException $e) { $threw = true; }
    check('a malformed registry throws: ' . $label, $threw);
}
@unlink($tmp);
ModeRegistry::useFile(null);
check('the real registry loads again after a failure', ModeRegistry::modeOfType('unique') === 'unique');
$threw = false;
try { ModeRegistry::mode('nope'); } catch (\InvalidArgumentException $e) { $threw = true; }
check('an unknown mode name throws', $threw);

echo "registry_php: $n checks, $fails failure(s)\n";
exit($fails ? 1 : 0);
