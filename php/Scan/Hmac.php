<?php

namespace INSPIRE\UniversalValidator\Scan;

/**
 * Keyed hashes for the scan store, separated by PURPOSE and by PROJECT.
 *
 * WHY SEPARATION IS NOT OPTIONAL. The store hashes four different things:
 * record identifiers (so a hashed-presentation project never writes a raw id
 * into a report), finding identities (so an incremental run can close and
 * reopen the same row), value fingerprints (so a changed value is detectable
 * without keeping the value), and uniqueness groups (so duplicates are found
 * without storing a 64 KB Notes field per candidate).
 *
 * With ONE key and no purpose label, those spaces coincide. Two consequences,
 * both real: a value fingerprint equal to a record hash tells an observer that
 * a field contains a record id, and a group hash reused as a finding identity
 * makes two unrelated rows collide by construction. Purpose separation costs a
 * string concatenation and removes the whole class.
 *
 * PROJECT SEPARATION does the same across projects: without it, the same value
 * in two projects produces the same fingerprint, so a reader of one project's
 * report learns something about another's.
 *
 * VERSIONED, because the day a key rotates or an algorithm changes, old rows
 * must be recognisably old rather than silently wrong.
 *
 * The key itself comes from the module's existing protected-secret mechanism -
 * the same one hashedIdentifier() uses. This class never invents, stores or logs
 * a key; a null key is an error the caller must handle, never a fallback to an
 * unkeyed hash, because an unkeyed hash of a record id is a lookup table.
 */
final class Hmac
{
    const V = 'v1';

    // The four spaces. Adding a fifth means adding a constant here, so the set
    // is enumerable and a reviewer can see that none of them collide.
    const P_RECORD   = 'record';        // record id -> presentation/identity hash
    const P_FINDING  = 'finding';       // finding identity, for interval versioning
    const P_VALUE    = 'value';         // value fingerprint, without the value
    const P_UNIQUE   = 'unique';        // uniqueness group key

    /**
     * @param string      $purpose one of the P_* constants
     * @param int|string  $pid     project, so spaces never coincide across projects
     * @param string      $data    raw bytes; may be invalid UTF-8, which is why
     *                             everything here is byte-oriented
     * @param string      $key     the module's protected secret
     * @return string 32 raw bytes
     */
    public static function raw($purpose, $pid, $data, $key)
    {
        if (!in_array($purpose, [self::P_RECORD, self::P_FINDING, self::P_VALUE, self::P_UNIQUE], true)) {
            throw new \InvalidArgumentException('unknown hmac purpose: ' . (string) $purpose);
        }
        if (!is_string($key) || $key === '') {
            // NEVER an unkeyed fallback. An unkeyed hash of a record id is a
            // lookup table for anyone holding the report.
            throw new \RuntimeException('no HMAC key is available; the scan store cannot write');
        }
        // The purpose and project are part of the MESSAGE with an unambiguous
        // separator, so "record" + "1|x" and "record|1" + "x" cannot collide.
        $msg = self::V . "\0" . $purpose . "\0" . (string) $pid . "\0" . $data;
        return hash_hmac('sha256', $msg, $key, true);
    }

    /** The same value, hex-encoded, for contexts that cannot carry raw bytes. */
    public static function hex($purpose, $pid, $data, $key)
    {
        return bin2hex(self::raw($purpose, $pid, $data, $key));
    }

    /**
     * A finding's identity: what makes two findings "the same finding" across
     * runs, so an incremental run closes the old row rather than duplicating it.
     *
     * The tuple is location plus rule plus reason - deliberately NOT the value.
     * A field whose wrong value changed from one wrong value to another is the
     * same finding with a new value, and treating it as a new finding would make
     * every re-scan look like churn.
     */
    public static function findingIdentity($pid, array $loc, $key)
    {
        $parts = [];
        foreach (['record', 'event_id', 'instance', 'host_form', 'field',
                  'rule_source_id', 'reason_code'] as $k) {
            $v = isset($loc[$k]) ? $loc[$k] : '';
            $parts[] = is_scalar($v) ? (string) $v : '';
        }
        return self::raw(self::P_FINDING, $pid, implode("\0", $parts), $key);
    }
}
