<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Accounts\IAccountManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IUserManager;
use OCP\Server;

/** Executes CardDAV contact and Nextcloud profile tools. */
final class ContactsToolExecutor implements DomainToolExecutor {
    private const TOOLS = ['list_contacts', 'find_contact', 'create_contact', 'update_contact', 'delete_contact', 'read_profile', 'update_profile'];

    public function __construct(
        private IContactsManager $contacts,
        private IAccountManager $accounts,
        private IUserManager $userManager,
    ) {
    }

    public function tools(): array {
        return self::TOOLS;
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_contacts' => $this->listContacts($userId),
            'find_contact' => $this->findContact($userId, $args),
            'create_contact' => $this->createContact($userId, $args),
            'update_contact' => $this->updateContact($userId, $args),
            'delete_contact' => $this->deleteContact($userId, $args),
            'read_profile' => $this->readProfile($userId),
            'update_profile' => $this->updateProfile($userId, $args),
            default => ['ok' => false, 'error' => 'Unsupported contact tool: ' . $tool],
        };
    }

    private function listContacts(string $userId): array {
        $out = [];
        $seen = [];
        $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
        foreach ($this->allAddressBookPrincipals($userId) as $principal) {
            // Die System-/System-Adressbuecher enthalten nur Selbst-Kontakte
            // aller Nutzer und gehoeren nicht zu den Kontakten des Users.
            if ($principal === 'principals/system/system') {
                continue;
            }
            foreach ($backend->getAddressBooksForUser($principal) as $book) {
                foreach ($backend->getCards((int)$book['id']) as $card) {
                    $entry = $this->extractContact((string)($card['carddata'] ?? ''));
                    if ($entry === null) {
                        continue;
                    }
                    $dedup = strtolower(($entry['name'] ?? '') . '|' . implode(',', $entry['emails'] ?? []));
                    if ($dedup !== '' && isset($seen[$dedup])) {
                        continue;
                    }
                    $seen[$dedup] = true;
                    $out[] = $entry;
                    if (count($out) >= 100) {
                        break 3;
                    }
                }
            }
        }
        return ['ok' => true, 'result' => ['contacts' => $out, 'count' => count($out)]];
    }

    private function findContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            // Ohne Suchbegriff: alle Kontakte auflisten (Frage "Welche Kontakte habe ich?").
            return $this->listContacts($userId);
        }
        $results = $this->contacts->search($query, ['FN', 'NICKNAME', 'EMAIL', 'ORG']);
        $out = [];
        foreach (array_slice($results, 0, 8) as $c) {
            $out[] = [
                'name' => $c['FN'] ?? $c['NICKNAME'] ?? '',
                'emails' => array_values(array_map('strval', (array)($c['EMAIL'] ?? []))),
                'phones' => array_values(array_map('strval', (array)($c['TEL'] ?? []))),
                'org' => $c['ORG'] ?? '',
            ];
        }
        if ($out === []) {
            $found = $this->findContactCard($userId, $query);
            if ($found !== null) {
                $vc = \Sabre\VObject\Reader::read($found['carddata']);
                $entry = [];
                foreach (['FN' => 'name', 'EMAIL' => 'emails', 'TEL' => 'phones', 'ORG' => 'org'] as $propName => $key) {
                    $vals = [];
                    foreach ($vc->select($propName) as $prop) {
                        $val = trim((string)$prop);
                        if ($val !== '') {
                            $vals[] = $val;
                        }
                    }
                    $entry[$key] = ($propName === 'FN') ? (string)($vals[0] ?? '') : $vals;
                }
                $out[] = $entry;
            }
        }
        return ['ok' => true, 'result' => ['query' => $query, 'contacts' => $out]];

    }

    private function extractContact(string $carddata): ?array {
        if ($carddata === '') {
            return null;
        }
        try {
            $v = \Sabre\VObject\Reader::read($carddata);
        } catch (\Throwable $e) {
            return null;
        }
        $entry = [];
        foreach (['FN' => 'name', 'EMAIL' => 'emails', 'TEL' => 'phones', 'ORG' => 'org'] as $propName => $key) {
            $vals = [];
            foreach ($v->select($propName) as $prop) {
                $val = trim((string)$prop);
                if ($val !== '') {
                    $vals[] = $val;
                }
            }
            $entry[$key] = ($propName === 'FN') ? (string)($vals[0] ?? '') : $vals;
        }
        if (($entry['name'] ?? '') === '' && ($entry['emails'] ?? []) === [] && ($entry['phones'] ?? []) === []) {
            return null;
        }
        return $entry;
    }

    private function createContact(string $userId, array $args): array {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Contact name required'];
        }
        $email = trim((string)($args['email'] ?? ''));
        $phone = trim((string)($args['phone'] ?? ''));
        $org = trim((string)($args['org'] ?? ''));

        $uid = 'ai-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . $uid . "\r\nFN:" . $name . "\r\nN:" . $name . ";;;;\r\n";
        if ($email !== '') {
            $vcard .= "EMAIL;TYPE=HOME:" . $email . "\r\n";
        }
        if ($phone !== '') {
            $vcard .= "TEL;TYPE=CELL:" . $phone . "\r\n";
        }
        if ($org !== '') {
            $vcard .= "ORG:" . $org . "\r\n";
        }
        $vcard .= "END:VCARD\r\n";

        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $books = $backend->getAddressBooksForUser('principals/users/' . $userId);
            if ($books === []) {
                return ['ok' => false, 'error' => 'No address book found for user'];
            }
            $backend->createCard((int)$books[0]['id'], $uid . '.vcf', $vcard);
            return ['ok' => true, 'result' => 'Created contact "' . $name . '"'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Address book write failed: ' . $e->getMessage()];
        }
    }

    private function readProfile(string $userId): array {
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return ['ok' => false, 'error' => 'User not found'];
        }
        try {
            $account = $this->accounts->getAccount($userObj);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile unavailable: ' . $e->getMessage()];
        }
        $fields = [
            'display_name' => IAccountManager::PROPERTY_DISPLAYNAME,
            'email' => IAccountManager::PROPERTY_EMAIL,
            'phone' => IAccountManager::PROPERTY_PHONE,
            'website' => IAccountManager::PROPERTY_WEBSITE,
            'address' => IAccountManager::PROPERTY_ADDRESS,
            'organisation' => IAccountManager::PROPERTY_ORGANISATION,
            'role' => IAccountManager::PROPERTY_ROLE,
            'headline' => IAccountManager::PROPERTY_HEADLINE,
            'biography' => IAccountManager::PROPERTY_BIOGRAPHY,
            'pronouns' => IAccountManager::PROPERTY_PRONOUNS,
        ];
        $profile = [];
        foreach ($fields as $label => $prop) {
            $value = $account->getProperty($prop)->getValue();
            if ($value !== '' && $value !== null) {
                $profile[$label] = $value;
            }
        }
        return ['ok' => true, 'result' => ['profile' => $profile]];
    }

    private function updateProfile(string $userId, array $args): array {
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return ['ok' => false, 'error' => 'User not found'];
        }
        try {
            $account = $this->accounts->getAccount($userObj);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile unavailable: ' . $e->getMessage()];
        }
        $map = [
            'display_name' => [IAccountManager::PROPERTY_DISPLAYNAME, IAccountManager::VERIFIED],
            'email' => [IAccountManager::PROPERTY_EMAIL, IAccountManager::NOT_VERIFIED],
            'phone' => [IAccountManager::PROPERTY_PHONE, IAccountManager::NOT_VERIFIED],
            'website' => [IAccountManager::PROPERTY_WEBSITE, IAccountManager::NOT_VERIFIED],
            'address' => [IAccountManager::PROPERTY_ADDRESS, IAccountManager::NOT_VERIFIED],
            'organisation' => [IAccountManager::PROPERTY_ORGANISATION, IAccountManager::NOT_VERIFIED],
            'role' => [IAccountManager::PROPERTY_ROLE, IAccountManager::NOT_VERIFIED],
            'headline' => [IAccountManager::PROPERTY_HEADLINE, IAccountManager::NOT_VERIFIED],
            'biography' => [IAccountManager::PROPERTY_BIOGRAPHY, IAccountManager::NOT_VERIFIED],
            'pronouns' => [IAccountManager::PROPERTY_PRONOUNS, IAccountManager::NOT_VERIFIED],
        ];
        $changed = [];
        foreach ($map as $key => [$prop, $verified]) {
            if (!array_key_exists($key, $args) || !is_string($args[$key])) {
                continue;
            }
            $account->setProperty($prop, trim($args[$key]), IAccountManager::SCOPE_LOCAL, $verified);
            $changed[] = $key;
        }
        if ($changed === []) {
            return ['ok' => false, 'error' => 'No profile fields to update'];
        }
        try {
            $this->accounts->updateAccount($account);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Profile update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Updated profile fields: ' . implode(', ', $changed)];
    }

    private function findContactCard(string $userId, string $query): ?array {
        $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
        foreach ($this->allAddressBookPrincipals($userId) as $principal) {
            foreach ($backend->getAddressBooksForUser($principal) as $book) {
            foreach ($backend->getCards((int)$book['id']) as $card) {
                $carddata = (string)($card['carddata'] ?? '');
                if ($carddata === '') {
                    continue;
                }
                try {
                    $v = \Sabre\VObject\Reader::read($carddata);
                } catch (\Throwable $e) {
                    continue;
                }
                $hayParts = [];
                foreach (['FN', 'EMAIL', 'TEL', 'ORG'] as $propName) {
                    foreach ($v->select($propName) as $prop) {
                        $val = trim((string)$prop);
                        if ($val !== '') {
                            $hayParts[] = $val;
                        }
                    }
                }
                $hay = mb_strtolower(implode(' ', $hayParts));
                if (mb_strpos($hay, mb_strtolower($query)) !== false) {
                    return [
                        'bookId' => (int)$book['id'],
                        'uri' => (string)($card['uri'] ?? ''),
                        'carddata' => $carddata,
                        'principaluri' => (string)($book['principaluri'] ?? ''),
                    ];
                }
            }
            }
        }
        return null;
    }

    private function addressBookWritable(string $userId, array $found): bool {
        $principal = (string)($found['principaluri'] ?? '');
        if ($principal === 'principals/users/' . $userId) {
            return true;
        }
        // Shared books are only writable with an explicit write grant.
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            foreach ($backend->getShares((int)$found['bookId']) as $share) {
                $href = (string)($share['href'] ?? '');
                if ($href === 'principal:principals/users/' . $userId) {
                    return empty($share['readOnly']);
                }
            }
        } catch (\Throwable $e) {
            // fall through: unknown -> not writable
        }
        return false;
    }

    private function allAddressBookPrincipals(string $userId): array {
        $principals = ['principals/users/' . $userId];
        $user = Server::get(\OCP\IUserManager::class)->get($userId);
        if ($user !== null) {
            foreach (Server::get(\OCP\IGroupManager::class)->getUserGroupIds($user) as $gid) {
                $principals[] = 'principals/groups/' . $gid;
            }
        }
        if (Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('circles')) {
            $principals[] = 'principals/circles/' . $userId;
        }
        $principals[] = 'principals/system/system';
        return array_values(array_unique($principals));
    }

    private function updateContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Contact query required'];
        }
        $found = $this->findContactCard($userId, $query);
        if ($found === null) {
            return ['ok' => false, 'error' => 'Contact not found'];
        }
        if (!$this->addressBookWritable($userId, $found)) {
            return ['ok' => false, 'error' => 'Contact lives in a read-only address book (shared or system). Only your own address books can be modified.'];
        }
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $vc = \Sabre\VObject\Reader::read($found['carddata']);
            foreach (['FN' => 'name', 'EMAIL' => 'email', 'TEL' => 'phone', 'ORG' => 'org'] as $prop => $key) {
                if (!array_key_exists($key, $args) || !is_string($args[$key])) {
                    continue;
                }
                $value = trim($args[$key]);
                $vc->remove($prop);
                if ($value !== '') {
                    $vc->add($prop, $value);
                }
            }
            $backend->updateCard($found['bookId'], $found['uri'], $vc->serialize());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Contact update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Updated contact "' . $query . '"'];
    }

    private function deleteContact(string $userId, array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Contact query required'];
        }
        $found = $this->findContactCard($userId, $query);
        if ($found === null) {
            return ['ok' => false, 'error' => 'Contact not found'];
        }
        if (!$this->addressBookWritable($userId, $found)) {
            return ['ok' => false, 'error' => 'Contact lives in a read-only address book (shared or system). Only your own address books can be modified.'];
        }
        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $backend->deleteCard($found['bookId'], $found['uri']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Contact delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted contact'];
    }
}
