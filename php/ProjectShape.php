<?php
namespace INSPIRE\UniversalValidator;

/** Immutable, normalized metadata. Unknown components never become empty facts. */
final class ProjectShape
{
    private $events;
    private $fields;
    private $fieldsKnown;

    /**
     * events[id] = {name, arm, order, forms: ?array<string>, repeats: ?array<string>,
     *                eventRepeats: ?bool}. fields[field] = {form, type?, validation?}.
     * Adapters must supply authoritative arm/order, never infer them from names.
     */
    public function __construct(array $events, array $fields, $fieldsKnown = true)
    {
        $this->events = $events;
        $this->fields = $fields;
        $this->fieldsKnown = (bool)$fieldsKnown;
    }

    public function fieldsKnown() { return $this->fieldsKnown; }
    public function field($name) { return isset($this->fields[$name]) ? $this->fields[$name] : null; }
    public function event($id) { return isset($this->events[$id]) ? $this->events[$id] : null; }
    public function events() { return $this->events; }

    public function eventId($token, $current, $form)
    {
        if ($token === null || $token === 'event-name') {
            return $this->event($current) === null ? ['state'=>'unreadable'] : ['state'=>'ok','event'=>$current];
        }
        if (!in_array($token, ['previous-event-name','next-event-name','first-event-name','last-event-name'], true)) {
            foreach ($this->events as $id=>$event) {
                if (isset($event['name']) && $event['name'] === $token) return ['state'=>'ok','event'=>$id];
            }
            return ['state'=>'invalid'];
        }
        $source = $this->event($current);
        if (!$source || !isset($source['arm'], $source['order'])) return ['state'=>'unreadable'];
        $eligible = [];
        foreach ($this->events as $id=>$event) {
            if (!isset($event['arm'])) return ['state'=>'unreadable'];
            if ((string)$event['arm'] !== (string)$source['arm']) continue;
            if (!isset($event['order']) || !isset($event['forms']) || !is_array($event['forms'])) return ['state'=>'unreadable'];
            if (!in_array($form, $event['forms'], true)) continue;
            if ($token === 'previous-event-name' && $event['order'] >= $source['order']) continue;
            if ($token === 'next-event-name' && $event['order'] <= $source['order']) continue;
            if (isset($eligible[(string)$event['order']])) return ['state'=>'unreadable'];
            $eligible[(string)$event['order']] = $id;
        }
        if (!$eligible) return ['state'=>'absent'];
        ksort($eligible, SORT_NUMERIC);
        $id = in_array($token, ['previous-event-name','last-event-name'], true) ? end($eligible) : reset($eligible);
        return ['state'=>'ok','event'=>$id];
    }

    /** bucket=null is a base row; '' is a whole repeating event. */
    public function bucket($eventId, $form)
    {
        $event = $this->event($eventId);
        if (!$event || !isset($event['forms']) || !is_array($event['forms'])) return ['state'=>'unreadable'];
        if (!in_array($form, $event['forms'], true)) return ['state'=>'missing'];
        if (!array_key_exists('eventRepeats', $event) || !is_bool($event['eventRepeats'])) return ['state'=>'unreadable'];
        if ($event['eventRepeats']) return ['state'=>'ok','bucket'=>''];
        if (!isset($event['repeats']) || !is_array($event['repeats'])) return ['state'=>'unreadable'];
        return ['state'=>'ok','bucket'=>in_array($form, $event['repeats'], true) ? $form : null];
    }

    public function digest()
    {
        $canonical = function ($value) use (&$canonical) {
            if (!is_array($value)) return $value;
            if (array_keys($value) !== range(0, count($value)-1)) ksort($value, SORT_STRING);
            foreach ($value as &$child) $child = $canonical($child);
            return $value;
        };
        return hash('sha256', json_encode($canonical(['events'=>$this->events,'fields'=>$this->fields,'fieldsKnown'=>$this->fieldsKnown])));
    }
}
