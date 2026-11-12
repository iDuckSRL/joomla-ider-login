<?php

/**
 * @package     IDer Login for Joomla
 * @copyright   (C) IDer
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace IDer\Plugin\System\IderLogin\Sync;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Collega i dati IDer a un utente Joomla: lo trova (per ID IDer o email),
 * lo crea se permesso e aggiorna nome e profilo.
 */
final class UserSync
{
    public const TABLE = '#__iderlogin_users';

    /** @var string[] campi aggiornati in questo accesso */
    public array $updated = [];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Dati IDer in forma piatta: valori JSON decodificati, oggetti appiattiti
     * con il punto ("address.locality"), liste ridotte al primo valore.
     */
    public static function normalize(array $claims): array
    {
        $decode = static function ($value) use (&$decode) {
            if (\is_string($value)) {
                $trim = trim($value);

                if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                    $json = json_decode($trim, true);

                    if (\is_array($json)) {
                        return $decode($json);
                    }
                }

                return $value;
            }

            if (\is_array($value)) {
                // claim ripetuto (es. email due volte): vale il primo
                if (array_is_list($value) && $value !== [] && !\is_array($value[0])) {
                    return $decode($value[0]);
                }

                foreach ($value as $k => $v) {
                    $value[$k] = $decode($v);
                }
            }

            return $value;
        };

        $flat = [];
        $walk = static function (array $data, string $prefix) use (&$walk, &$flat): void {
            foreach ($data as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;

                if (\is_array($value)) {
                    $walk($value, $name);
                } elseif ($value !== null && $value !== '') {
                    $flat[$name] = \is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
                }
            }
        };
        $walk($decode($claims), '');

        return $flat;
    }

    /**
     * @throws UserMessageException con un testo per l'utente (chiave di lingua)
     */
    public function syncUser(array $c, bool $allowRegistration, bool $updateData, bool $allowBackendUsers): User
    {
        $sub   = $c['sub'] ?? '';
        $email = mb_strtolower($c['email'] ?? '');

        if ($sub === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserMessageException('PLG_SYSTEM_IDERLOGIN_ERR_NO_EMAIL');
        }

        $userId = $this->userIdBySub($sub);

        if ($userId && !$this->userExists($userId)) {
            $this->deleteLink($sub);
            $userId = 0;
        }

        if (!$userId) {
            $userId = $this->userIdByEmail($email);
        }

        if (!$userId) {
            if (!$allowRegistration) {
                throw new UserMessageException('PLG_SYSTEM_IDERLOGIN_ERR_NO_ACCOUNT');
            }

            $user = $this->createUser($c, $email);
        } else {
            $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
        }

        if ($user->block || !empty($user->activation)) {
            throw new UserMessageException('PLG_SYSTEM_IDERLOGIN_ERR_BLOCKED');
        }

        // per sicurezza gli account del pannello di amministrazione non si collegano a IDer
        if (!$allowBackendUsers && ($user->authorise('core.login.admin') || $user->authorise('core.admin'))) {
            throw new UserMessageException('PLG_SYSTEM_IDERLOGIN_ERR_BACKEND_USER');
        }

        $changed = false;

        // email cambiata in IDer: aggiorna se nessun altro utente la usa
        if (mb_strtolower($user->email) !== $email && !$this->userIdByEmail($email)) {
            $user->email = $email;
            $this->updated[] = 'email';
            $changed = true;
        }

        if ($updateData) {
            $name = $this->fullName($c);

            if ($name !== '' && $name !== $user->name) {
                $user->name = $name;
                $this->updated[] = 'name';
                $changed = true;
            }
        }

        if ($changed && !$user->save(true)) {
            throw new \RuntimeException('User update failed');
        }

        if ($updateData) {
            $this->saveProfile($user->id, $c);
        }

        $this->saveLink($sub, (int) $user->id, $c);

        return $user;
    }

    private function createUser(array $c, string $email): User
    {
        $params = ComponentHelper::getParams('com_users');
        $group  = (int) $params->get('new_usertype', 2);

        $user = new User();
        $data = [
            'name'     => $this->fullName($c) ?: $email,
            'username' => $this->freeUsername($email),
            'email'    => $email,
            'password' => UserHelper::genRandomPassword(32),
            'block'    => 0,
            'groups'   => [$group],
        ];

        if (!$user->bind($data) || !$user->save()) {
            throw new \RuntimeException('User creation failed: ' . $user->getError());
        }

        $this->updated[] = 'created';

        return $user;
    }

    private function fullName(array $c): string
    {
        $name = trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? ''));
        $name = trim((string) preg_replace('/[<>"%;()&\\\\]|\s+/u', ' ', $name));

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 400);
    }

    private function freeUsername(string $email): string
    {
        $base = mb_substr($email, 0, 140);
        $name = $base;

        for ($i = 2; UserHelper::getUserId($name); $i++) {
            $name = $base . '-' . $i;
        }

        return $name;
    }

    // ------------------------------------------------------------------
    // Profilo (plugin "Utente - Profilo" di Joomla)
    // ------------------------------------------------------------------

    private function saveProfile(int $userId, array $c): void
    {
        if (!PluginHelper::isEnabled('user', 'profile')) {
            return;
        }

        $values = [
            'profile.address1'    => $c['address.street_address'] ?? '',
            'profile.city'        => $c['address.locality'] ?? '',
            'profile.region'      => $c['address.region'] ?? '',
            'profile.country'     => $c['address.country'] ?? '',
            'profile.postal_code' => $c['address.postal_code'] ?? '',
            'profile.phone'       => $c['phone_number'] ?? '',
            'profile.dob'         => self::parseDate($c['birthdate'] ?? '') ?? '',
            'profile.website'     => filter_var($c['website'] ?? '', FILTER_VALIDATE_URL) ? $c['website'] : '',
        ];

        $values = array_filter($values, static fn ($v) => $v !== '');

        if (!$values) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('profile_key'), $this->db->quoteName('profile_value')])
            ->from($this->db->quoteName('#__user_profiles'))
            ->where($this->db->quoteName('user_id') . ' = :uid')
            ->bind(':uid', $userId, ParameterType::INTEGER);
        $current = $this->db->setQuery($query)->loadAssocList('profile_key', 'profile_value');

        foreach ($values as $key => $value) {
            $json = json_encode(mb_substr($value, 0, 255));

            if (($current[$key] ?? null) === $json) {
                continue;
            }

            $delete = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__user_profiles'))
                ->where($this->db->quoteName('user_id') . ' = :uid')
                ->where($this->db->quoteName('profile_key') . ' = :key')
                ->bind(':uid', $userId, ParameterType::INTEGER)
                ->bind(':key', $key);
            $this->db->setQuery($delete)->execute();

            $row = (object) ['user_id' => $userId, 'profile_key' => $key, 'profile_value' => $json, 'ordering' => 0];
            $this->db->insertObject('#__user_profiles', $row);
            $this->updated[] = $key;
        }
    }

    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return \sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : null;
    }

    // ------------------------------------------------------------------
    // Collegamento sub <-> utente
    // ------------------------------------------------------------------

    private function userIdBySub(string $sub): int
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('user_id'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('sub') . ' = :sub')
            ->bind(':sub', $sub);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function userIdByEmail(string $email): int
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where('LOWER(' . $this->db->quoteName('email') . ') = :email')
            ->bind(':email', $email);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function userExists(int $id): bool
    {
        $query = $this->db->getQuery(true)
            ->select('1')->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    public function deleteLink(string $sub): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('sub') . ' = :sub')
            ->bind(':sub', $sub);
        $this->db->setQuery($query)->execute();
    }

    public function deleteLinksForUser(int $userId): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('user_id') . ' = :uid')
            ->bind(':uid', $userId, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function saveLink(string $sub, int $userId, array $claims): void
    {
        unset($claims['sub']);
        $now = Factory::getDate()->toSql();
        $row = (object) [
            'sub'            => $sub,
            'user_id'        => $userId,
            'claims'         => json_encode($claims, JSON_UNESCAPED_UNICODE),
            'updated_fields' => implode(',', array_unique($this->updated)),
            'last_login'     => $now,
        ];

        if ($this->userIdBySub($sub)) {
            $this->db->updateObject(self::TABLE, $row, 'sub');

            return;
        }

        // un utente = un ID IDer
        $this->deleteLinksForUser($userId);
        $row->created = $now;
        $this->db->insertObject(self::TABLE, $row);
    }
}
