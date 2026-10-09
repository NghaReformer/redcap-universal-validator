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
check('tag map equals the old AnnotationRules::TAGS, in order', ModeRegistry::tagMap() === [
    '@UVALIDATE'  => 'check',
    '@UVASSERT'   => 'constraint',
    '@UVREQUIRED' => 'required',
    '@UVUNIQUE'   => 'unique',
    '@UVCHOICES'  => 'choices',
]);
foreach (['TAG' => 'check', 'TAG_ASSERT' => 'constraint', 'TAG_REQUIRED' => 'required',
          'TAG_UNIQUE' => 'unique', 'TAG_CHOICES' => 'choices'] as $const => $mode) {
    check('AnnotationRules::' . $const . ' is the ' . $mode . ' tag',
        constant(AnnotationRules::class . '::' . $const) === ModeRegistry::tag($mode));
}

// Branching::BRANCH_KEYS, in order (branchOf copies in this order).
check('branch keys equal the old Branching::BRANCH_KEYS, in order', ModeRegistry::branchKeys() === [
    'algorithm', 'idPattern', 'alternates', 'source', 'strip', 'keepChars',
    'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds',
    'blockSave', 'suggestFix', 'note',
    'assert', 'caseSensitive', 'message', 'references',
    'uniqueWith', 'uniqueScope', 'uniqueSurveys',
    'choicesShow', 'choicesHide', 'choicesAll',
]);

// The engine's DEFAULT_KEYS, in order.
check('client keys equal the old engine DEFAULT_KEYS, in order', ModeRegistry::clientKeys() === [
    'algorithm', 'idPattern', 'alternates', 'source', 'strip', 'suggestFix',
    'keepChars', 'idLengths', 'idMinLen', 'idMaxLen', 'expectedIds',
    'blockSave', 'when', 'whenAst',
    'assert', 'assertAst', 'caseSensitive', 'message', 'deferred', 'deferredWhy', 'snapshotFields',
    'uniqueWith', 'uniqueScope', 'uniqueSurveys', 'uniqueRecordAsts',
    'choicesShow', 'choicesHide', 'choicesAll',
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

// ---- 2. coverage ------------------------------------------------------------

$config = json_decode(file_get_contents($root . '/config.json'), true);
$configTags = [];
foreach ($config['action-tags'] as $t) $configTags[] = $t['tag'];
$pages = file_get_contents($root . '/site/pages.py');
preg_match('/^TAG_SLUGS = \(([^)]*)\)/m', $pages, $m);
$slugs = $m ? array_map(function ($s) { return trim($s, " \"'"); }, explode(',', $m[1])) : [];
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
