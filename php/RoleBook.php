<?php
namespace INSPIRE\UniversalValidator;

use INSPIRE\UniversalValidator\Scan\ScanDb;

/**
 * A project's user roles and who holds them, for @UVWRITEONCE "roles", read
 * from REDCap's tables:
 *
 *  - redcap_user_roles: each role's name as the User Rights page shows it and
 *    its unique role name (U-...), when the installation has that column;
 *  - redcap_user_rights: the role each user of the project holds (role_id);
 *  - redcap_user_information: whether the account is suspended.
 *
 * A user whose rights in the project expire on or before $today (the date
 * REDCap's own clock gives, as REDCap compares it) or whose account is
 * suspended holds no role. Role names are kept as Logic::roleKey makes
 * them (any letter case, spaces folded), usernames in lowercase. A REDCap
 * with no unique role names is read without them.
 *
 * PHP 7.4.
 */
final class RoleBook
{
    /**
     * ['roles' => [role key => true], 'users' => [lowercase username =>
     * [role keys of the role the user holds]]], or null when the tables cannot
     * be read. A role key is Logic::roleKey of the name.
     */
    public static function read(ScanDb $db, $pid, $today)
    {
        foreach ([', r.unique_role_name', ''] as $unique) {
            try {
                $roleRows = $db->select('SELECT r.role_name' . $unique . ' FROM redcap_user_roles r WHERE r.project_id = ?', [(int) $pid]);
                $userRows = $db->select('SELECT u.username, r.role_name' . $unique . ' FROM redcap_user_rights u'
                    . ' JOIN redcap_user_roles r ON r.role_id = u.role_id'
                    . ' LEFT JOIN redcap_user_information i ON i.username = u.username'
                    . ' WHERE u.project_id = ? AND (u.expiration IS NULL OR u.expiration > ?) AND i.user_suspended_time IS NULL',
                    [(int) $pid, (string) $today]);
            } catch (\Throwable $e) {
                continue;
            }
            $book = ['roles' => [], 'users' => []];
            foreach ($roleRows as $row) {
                foreach ([0, 1] as $c) if (isset($row[$c]) && trim((string) $row[$c]) !== '') $book['roles'][Logic::roleKey($row[$c])] = true;
            }
            foreach ($userRows as $row) {
                $u = isset($row[0]) ? strtolower(trim((string) $row[0])) : '';
                if ($u === '') continue;
                foreach ([1, 2] as $c) if (isset($row[$c]) && trim((string) $row[$c]) !== '') $book['users'][$u][] = Logic::roleKey($row[$c]);
            }
            return $book;
        }
        return null;
    }
}
