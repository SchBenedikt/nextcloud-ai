<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IConfig;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * Kalender-Zugriff für die KI: Kalender auflisten, Termine erstellen,
 * ändern und Termine löschen über den CalDAV-Backend (eigener Benutzer).
 */
class CalendarService {
    public function __construct(
        private CalDavBackend $backend,
        private IConfig $config
    ) {
    }

    /** Zeitzone des Nutzers (Fallback: Europe/Berlin), als DateTimeZone. */
    private function userTimeZone(string $userId): \DateTimeZone {
        $tz = $this->config->getUserValue($userId, 'core', 'timezone', 'Europe/Berlin');
        try {
            return new \DateTimeZone($tz !== '' ? $tz : 'Europe/Berlin');
        } catch (\Throwable $e) {
            return new \DateTimeZone('Europe/Berlin');
        }
    }

    private function principal(string $userId): string {
        return 'principals/users/' . $userId;
    }

    /** @return array<int,array{id:int,uri:string,displayname:string,color?:string,principaluri:string,readOnly:bool}> */
    public function calendars(string $userId): array {
        $out = [];
        foreach ($this->allCalendarPrincipals($userId) as $principal) {
            foreach ($this->backend->getCalendarsForUser($principal) as $cal) {
                $out[] = [
                    'id' => (int)$cal['id'],
                    'uri' => (string)$cal['uri'],
			        'displayname' => (string)($cal['{DAV:}displayname'] ?? $cal['uri']),
                    'color' => (string)($cal['{http://apple.com/ns/ical/}calendar-color'] ?? ''),
                    'principaluri' => (string)($cal['principaluri'] ?? $principal),
                    'readOnly' => (int)($cal['{http://owncloud.org/ns}read-only'] ?? 0) === 1,
                ];
            }
        }
        return $out;
    }

    /**
     * Whether the current user may mutate a calendar. Personal calendars (own
     * principal), group/circle calendars (membership) and shared calendars with
     * an explicit write grant (read-only flag 0) are writable. Read-only shared
     * and system/subscription calendars are rejected before any DAV mutation
     * (Issue #11).
     */
    private function calendarWritable(array $cal, string $userId): bool {
        $principal = (string)($cal['principaluri'] ?? '');
        if ($principal === 'principals/users/' . $userId) {
            return true;
        }
        if (str_starts_with($principal, 'principals/groups/')
            || str_starts_with($principal, 'principals/circles/')) {
            // Group/circle calendars: membership implies write access.
            return true;
        }
        // Shared calendar: writable only with an explicit write grant.
        return !$cal['readOnly'];
    }

    /**
     * Alle Kalender-Prinzipalen, auf die der Nutzer Zugriff hat:
     * eigene, geteilte, Gruppen-Kalender sowie das System-Subskriptions-Ende.
     * @return list<string>
     */
    private function allCalendarPrincipals(string $userId): array {
        $principals = ['principals/users/' . $userId];
        $user = \OCP\Server::get(\OCP\IUserManager::class)->get($userId);
        if ($user !== null) {
            foreach (\OCP\Server::get(\OCP\IGroupManager::class)->getUserGroupIds($user) as $gid) {
                $principals[] = 'principals/groups/' . $gid;
            }
        }
        if (\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('circles')) {
            $principals[] = 'principals/circles/' . $userId;
        }
        return array_values(array_unique($principals));
    }

    /** @return array{id:int,uri:string,displayname:string,color?:string,principaluri:string,readOnly:bool}|null */
    private function resolveCalendar(string $userId, ?string $hint, bool $writableOnly = false): ?array {
        $cals = $this->calendars($userId);
        // Default write target: the user's personal (own principal) calendar.
        if ($hint === null || $hint === '') {
            foreach ($cals as $cal) {
                if (($cal['principaluri'] ?? '') === 'principals/users/' . $userId
                    && (!$writableOnly || $this->calendarWritable($cal, $userId))) {
                    return $cal;
                }
            }
            foreach ($cals as $cal) {
                if (!$writableOnly || $this->calendarWritable($cal, $userId)) {
                    return $cal;
                }
            }
            return null;
        }
        foreach ($cals as $cal) {
            if ($hint === (string)$cal['id'] || $hint === $cal['uri'] || strcasecmp($hint, (string)$cal['displayname']) === 0) {
                return $cal;
            }
        }
        return null;
    }

    private function parseTime(string $val, string $userId, bool &$allDay): ?\DateTimeImmutable {
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        $tz = $this->userTimeZone($userId);

        // Nur Datum -> Ganztages-Termin
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            $allDay = true;
            return \DateTimeImmutable::createFromFormat('!Y-m-d', $val) ?: null;
        }
        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $val)) {
            $allDay = true;
            return \DateTimeImmutable::createFromFormat('!d.m.Y', $val) ?: null;
        }
        // ISO mit T und optional Z/Offset: DateTime versteht das direkt (UTC!)
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $val)) {
            $hasOffset = (bool)preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $val);
            // Modelle senden lokale Uhrzeiten gern mit "Z" (irrtümlich als UTC).
            // Fuer Nicht-UTC-Nutzer: Z-Suffix als lokale Zeit interpretieren.
            if (substr($val, -1) === 'Z' && $tz->getName() !== 'UTC') {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', str_replace(' ', 'T', substr($val, 0, -1)), $tz);
                if ($d !== false) {
                    return $d;
                }
            }
            if (!$hasOffset) {
                $d = new \DateTimeImmutable(str_replace(' ', 'T', $val), $tz);
                return $d ?? null;
            }
            $d = new \DateTimeImmutable($val);
            return $d ?? null;
        }
        // Datum mit Uhrzeit: deutsche + ISO-Schreibweisen
        $formats = [
            '!d.m.Y H:i', '!d.m.Y H:i:s', '!d.m.Y-H:i', '!d.m.Y. H:i', '!d.m.Y., H:i',
            '!Y-m-d H:i', '!Y-m-d H:i:s', '!d/m/Y H:i', '!d.m.Y G:i',
        ];
        foreach ($formats as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . ltrim($f, '!'), $val, $tz);
            if ($d !== false) {
                $errs = \DateTimeImmutable::getLastErrors();
                if ($errs === false || ($errs['warning_count'] ?? 0) === 0) {
                    return $d;
                }
            }
        }
        // Relative Angaben wie "morgen 14:00", "nächste Woche Mittwoch 09:30", "in 2 Stunden"
        // Wichtig: mit expliziter Zeitzone parsen, sonst gilt die Server-UTC-Zeitzone!
        $rel = $this->germanToEnglish($val);
        try {
            $d = new \DateTimeImmutable($rel, $tz);
            return $d;
        } catch (\Throwable $e) {
            // Fallback: strtotime (Default-Zeitzone des Servers)
            $ts = @strtotime($rel);
            if ($ts !== false) {
                $d = \DateTimeImmutable::createFromFormat('U', (string)$ts);
                if ($d !== false) {
                    return $d->setTimezone($tz);
                }
            }
        }
        // Wochentag-Präfix wie "Dienstag, 25.08.2026 14:00" abstreifen
        $val2 = preg_replace('/^[a-zA-ZäöüÄÖÜ]+\s*[,.]?\s*/', '', $val, 1);
        if ($val2 !== $val) {
            $d = $this->parseTime($val2, $userId, $allDay);
            if ($d !== null) {
                return $d;
            }
        }
        return null;
    }

    /** Übersetzt deutsche Zeitangaben in Formate, die strtotime versteht. */
    private function germanToEnglish(string $val): string {
        $v = trim((string)preg_replace('/\s+/', ' ', $val));
        if (preg_match('/^in (\d+) (minuten?|stunde(?:n)?|tag(?:e|en)?)(?:\s*um\s*(\d{1,2}:\d{2}))?$/i', $v, $m)) {
            $parts = $m[2];
            $suffix = isset($m[3]) ? ' ' . $m[3] : '';
            if (str_starts_with($parts, 'm')) {
                return '+' . (int)$m[1] . ' minutes' . $suffix;
            }
            if (str_starts_with($parts, 's')) {
                return '+' . (int)$m[1] . ' hours' . $suffix;
            }
            return '+' . (int)$m[1] . ' days' . $suffix;
        }
        $words = [
            'übermorgen' => '+2 days', 'uebermorgen' => '+2 days',
            'gestern' => 'yesterday', 'heute' => 'today', 'morgen' => 'tomorrow',
            'nächste woche' => 'next week', 'naechste woche' => 'next week',
            'nächste' => 'next', 'naechste' => 'next',
            'montag' => 'monday', 'dienstag' => 'tuesday', 'mittwoch' => 'wednesday',
            'donnerstag' => 'thursday', 'freitag' => 'friday', 'samstag' => 'saturday', 'sonntag' => 'sunday',
            'januar' => 'january', 'februar' => 'february', 'märz' => 'march', 'maerz' => 'march',
            'juni' => 'june', 'juli' => 'july', 'august' => 'august', 'september' => 'september',
            'oktober' => 'october', 'november' => 'november', 'dezember' => 'december',
        ];
        $res = '';
        foreach (preg_split('/\s+/', $v) as $tok) {
            $low = mb_strtolower($tok);
            if (isset($words[$low])) {
                $res .= ' ' . $words[$low];
            } elseif ($low === 'um' || $low === 'uhr') {
                continue;
            } else {
                $res .= ' ' . $tok;
            }
        }
        return trim($res);
    }

    private function buildIcs(string $uid, array $args, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, bool $allDay): string {
        $vc = new VCalendar();
        $ve = $vc->add('VEVENT');
        $ve->add('UID', $uid);
        $ve->add('DTSTAMP', new \DateTimeImmutable('now'));
        if ($start !== null) {
            if ($allDay) {
                $ve->add('DTSTART', $start->format('Ymd'));
            } else {
                $ve->add('DTSTART', $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            }
        }
        if ($end !== null) {
            if ($allDay) {
                $ve->add('DTEND', $end->format('Ymd'));
            } else {
                $ve->add('DTEND', $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            }
        }
        $ve->add('SUMMARY', (string)($args['summary'] ?? ''));
        if (($args['location'] ?? '') !== '') {
            $ve->add('LOCATION', (string)$args['location']);
        }
        if (($args['description'] ?? '') !== '') {
            $ve->add('DESCRIPTION', (string)$args['description']);
        }
        if (($args['categories'] ?? '') !== '') {
            $ve->add('CATEGORIES', (string)$args['categories']);
        }
        $reminder = max(0, (int)($args['reminder_minutes'] ?? 0));
        if ($reminder > 0) {
            $alm = $ve->add('VALARM');
            $alm->add('ACTION', 'DISPLAY');
            $alm->add('TRIGGER', '-PT' . $reminder . 'M');
            $alm->add('DESCRIPTION', (string)($args['summary'] ?? ''));
        }
        return $vc->serialize();
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function listEvents(string $userId, array $args): array {
        $cal = $this->resolveCalendar($userId, (string)($args['calendar'] ?? ''));
        $cals = $cal !== null ? [$cal] : $this->calendars($userId);
        if ($cals === []) {
            return ['ok' => false, 'error' => 'No calendar found for this user'];
        }

        $allDay = false;
        $start = $this->parseTime((string)($args['start_date'] ?? ''), $userId, $allDay);
        $end = $this->parseTime((string)($args['end_date'] ?? ''), $userId, $allDay);
        // Komfort-Shortcut: days=N => ab heute N Tage nach vorn, past_days=N => N Tage zurück.
        $days = isset($args['days']) ? max(0, (int)$args['days']) : 0;
        $pastDays = isset($args['past_days']) ? max(0, (int)$args['past_days']) : 0;
        $tz = $this->userTimeZone($userId);
        if ($start === null && ($days > 0 || $pastDays > 0)) {
            $start = (new \DateTimeImmutable('today', $tz))->modify('-' . $pastDays . ' days');
        }
        if ($start === null) {
            $start = new \DateTimeImmutable('today', $tz);
        }
        if ($end === null && $days > 0) {
            $end = $start->modify('+' . $days . ' days');
        }
        if ($end === null) {
            $end = $start->modify('+60 days');
        }
        // Kategorie-Filter (kommagetrennt oder Array).
        $catFilter = [];
        if (isset($args['categories'])) {
            $cats = $args['categories'];
            if (is_array($cats)) {
                $catFilter = array_map(static fn($c) => strtolower(trim((string)$c)), $cats);
            } else {
                $catFilter = array_filter(array_map(static fn($c) => strtolower(trim($c)), explode(',', (string)$cats)));
            }
            $catFilter = array_values(array_unique($catFilter));
        }

        $events = [];
        foreach ($cals as $c) {
            foreach ($this->backend->getCalendarObjects((int)$c['id']) as $obj) {
                if (strtolower((string)($obj['component'] ?? '')) !== 'vevent') {
                    continue;
                }
                $raw = $this->backend->getCalendarObject((int)$c['id'], (string)$obj['uri']);
                if (!isset($raw['calendardata'])) {
                    continue;
                }
                try {
                    $v = Reader::read((string)$raw['calendardata']);
                } catch (\Throwable $e) {
                    continue;
                }
                foreach ($this->expandEvents($v, $start, $end, $userId) as $occurrence) {
                    $ve = $occurrence['event'];
                    $dtstart = $occurrence['start'];
                    $dtend = $occurrence['end'];
                    $isAllDay = $occurrence['all_day'];
                    if ($catFilter !== []) {
                        $catStr = strtolower((string)($ve->CATEGORIES ?? ''));
                        $catList = $catStr === '' ? [] : array_map('trim', explode(',', $catStr));
                        if (count(array_intersect($catFilter, $catList)) === 0) {
                            continue;
                        }
                    }
                    $events[] = [
                        'id' => $c['uri'] . '/' . $obj['uri'],
                        'calendar' => (string)$c['displayname'],
                        'title' => (string)($ve->SUMMARY ?? ''),
                        'start' => $this->formatEventDateTime($dtstart, $isAllDay),
                        'end' => $dtend ? $this->formatEventDateTime($dtend, $isAllDay) : null,
                        'all_day' => $isAllDay,
                        'location' => (string)($ve->LOCATION ?? ''),
                        'description' => (string)($ve->DESCRIPTION ?? ''),
                        'categories' => $ve->CATEGORIES ? array_map('trim', explode(',', (string)$ve->CATEGORIES)) : [],
                    ];
                }
            }
        }
        usort($events, static fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
        return ['ok' => true, 'result' => ['events' => $events]];
    }

    /**
     * Expand one or more VEVENTs into bounded occurrences in the requested
     * range. EventIterator handles RRULE, RDATE, EXDATE and RECURRENCE-ID.
     * @return list<array{event:object,start:\DateTimeImmutable,end:?\DateTimeImmutable,all_day:bool}>
     */
    private function expandEvents(VCalendar $calendar, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, string $userId): array {
        $out = [];
        $seen = [];
        $tz = $this->userTimeZone($userId);
        foreach ($calendar->VEVENT as $event) {
            if (isset($event->{'RECURRENCE-ID'})) {
                continue;
            }
            try {
                $iterator = new EventIterator($calendar, (string)($event->UID ?? ''), $tz);
                $count = 0;
                foreach ($iterator as $occurrenceStart) {
                    if (++$count > 500) {
                        break;
                    }
                    $occurrenceStart = \DateTimeImmutable::createFromMutable($occurrenceStart);
                    if ($occurrenceStart > $rangeEnd) {
                        break;
                    }
                    $occurrenceEnd = $iterator->getDtEnd();
                    $occurrenceEnd = $occurrenceEnd ? \DateTimeImmutable::createFromMutable($occurrenceEnd) : null;
                    if ($occurrenceEnd !== null && $occurrenceEnd < $rangeStart) {
                        continue;
                    }
                    if ($occurrenceStart < $rangeStart && ($occurrenceEnd === null || $occurrenceEnd <= $rangeStart)) {
                        continue;
                    }
                    $key = (string)($event->UID ?? '') . ':' . $occurrenceStart->getTimestamp();
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = [
                        'event' => $event,
                        'start' => $occurrenceStart,
                        'end' => $occurrenceEnd,
                        'all_day' => !$event->DTSTART->hasTime(),
                    ];
                }
            } catch (\Throwable $e) {
                // Malformed recurrence rules must not make other calendar
                // entries unavailable; the master event is still useful.
                $start = $event->DTSTART ? $event->DTSTART->getDateTime($tz) : null;
                if ($start !== null && $start >= $rangeStart && $start <= $rangeEnd) {
                    $out[] = [
                        'event' => $event,
                        'start' => $start,
                        'end' => $event->DTEND ? $event->DTEND->getDateTime($tz) : null,
                        'all_day' => !$event->DTSTART->hasTime(),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Serialize a parsed calendar date without claiming that a local wall
     * clock value is UTC. Timed values are converted to UTC before the Z
     * suffix is emitted; all-day values intentionally remain date-only.
     */
    private function formatEventDateTime(\DateTimeInterface $date, bool $allDay): string {
        if ($allDay) {
            return $date->format('Y-m-d');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function createEvent(string $userId, array $args): array {
        $summary = trim((string)($args['summary'] ?? ''));
        if ($summary === '') {
            return ['ok' => false, 'error' => 'Event summary required'];
        }
        $cal = $this->resolveCalendar($userId, (string)($args['calendar'] ?? ''), true);
        if ($cal === null) {
            return ['ok' => false, 'error' => 'No writable calendar found. Create events only in your own calendars.'];
        }
        $allDay = false;
        $start = $this->parseTime((string)($args['start'] ?? ''), $userId, $allDay);
        if ($start === null) {
            return ['ok' => false, 'error' => 'Start date/time required. Examples: "2026-08-09T16:00:00Z", "03.08.2026 15:00", "morgen 10:00", "2026-08-09" (all-day).'];
        }
        $end = $this->parseTime((string)($args['end'] ?? ''), $userId, $allDay);
        if ($end === null && isset($args['duration_minutes'])) {
            $mins = max(1, min(24 * 60, (int)$args['duration_minutes']));
            $end = $allDay ? $start->modify('+1 day') : $start->modify('+' . $mins . ' minutes');
        }
        if ($end === null) {
            $end = $allDay ? $start->modify('+1 day') : $start->modify('+1 hour');
        }
        if ($end <= $start) {
            $end = $allDay ? $start->modify('+1 day') : $start->modify('+1 hour');
        }
        $uid = 'ai-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $ics = $this->buildIcs($uid, $args, $start, $end, $allDay);
        try {
            $this->backend->createCalendarObject((int)$cal['id'], $uid . '.ics', $ics);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Calendar write failed: ' . $e->getMessage()];
        }
        return [
            'ok' => true,
            'result' => [
                'event' => ['id' => $cal['uri'] . '/' . $uid . '.ics', 'title' => $summary, 'calendar' => (string)$cal['displayname']],
            ],
        ];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function updateEvent(string $userId, array $args): array {
        $id = trim((string)($args['event_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'event_id required (use the id from list_calendar_events)'];
        }
        $parts = explode('/', $id, 2);
        $cal = $this->resolveCalendar($userId, $parts[0] ?? '');
        if ($cal === null) {
            return ['ok' => false, 'error' => 'Calendar not found'];
        }
        if (!$this->calendarWritable($cal, $userId)) {
            return ['ok' => false, 'error' => 'Calendar is read-only (shared or system). Only your own calendars can be modified.'];
        }
        $uri = $parts[1] ?? '';
        $raw = $this->backend->getCalendarObject((int)$cal['id'], $uri);
        if ($raw === null || !isset($raw['calendardata'])) {
            return ['ok' => false, 'error' => 'Event not found'];
        }
        try {
            $v = Reader::read((string)$raw['calendardata']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not parse event: ' . $e->getMessage()];
        }
        $ve = $v->VEVENT ? $v->VEVENT[0] : null;
        if ($ve === null) {
            return ['ok' => false, 'error' => 'No VEVENT found'];
        }
        if (isset($args['summary']) && (string)$args['summary'] !== '') {
            $ve->SUMMARY = (string)$args['summary'];
        }
        if (array_key_exists('location', $args)) {
            if ((string)$args['location'] === '') {
                unset($ve->LOCATION);
            } else {
                $ve->LOCATION = (string)$args['location'];
            }
        }
        if (array_key_exists('description', $args)) {
            if ((string)$args['description'] === '') {
                unset($ve->DESCRIPTION);
            } else {
                $ve->DESCRIPTION = (string)$args['description'];
            }
        }
        if (array_key_exists('categories', $args)) {
            if (trim((string)$args['categories']) === '') {
                unset($ve->CATEGORIES);
            } else {
                $ve->CATEGORIES = (string)$args['categories'];
            }
        }
        if (isset($args['start']) && (string)$args['start'] !== '') {
            $allDay = false;
            $start = $this->parseTime((string)$args['start'], $userId, $allDay);
            if ($start === null) {
                return ['ok' => false, 'error' => 'Invalid start time'];
            }
            $ve->remove('DTSTART');
            $ve->add('DTSTART', $allDay ? $start->format('Ymd') : $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        }
        if (isset($args['end']) && (string)$args['end'] !== '') {
            $allDay = false;
            $end = $this->parseTime((string)$args['end'], $userId, $allDay);
            if ($end === null) {
                return ['ok' => false, 'error' => 'Invalid end time'];
            }
            $ve->remove('DTEND');
            $ve->add('DTEND', $allDay ? $end->format('Ymd') : $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        }
        if (array_key_exists('reminder_minutes', $args)) {
            while ($ve->VALARM) {
                $ve->remove('VALARM');
            }
            $reminder = max(0, (int)$args['reminder_minutes']);
            if ($reminder > 0) {
                $alm = $ve->add('VALARM');
                $alm->add('ACTION', 'DISPLAY');
                $alm->add('TRIGGER', '-PT' . $reminder . 'M');
                $alm->add('DESCRIPTION', (string)$ve->SUMMARY);
            }
        }
        try {
            $this->backend->updateCalendarObject((int)$cal['id'], $uri, $v->serialize());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Calendar update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['event' => ['id' => $id, 'title' => (string)$ve->SUMMARY]]];
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    public function deleteEvent(string $userId, array $args): array {
        $id = trim((string)($args['event_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'event_id required'];
        }
        $parts = explode('/', $id, 2);
        $cal = $this->resolveCalendar($userId, $parts[0] ?? '');
        if ($cal === null) {
            return ['ok' => false, 'error' => 'Calendar not found'];
        }
        if (!$this->calendarWritable($cal, $userId)) {
            return ['ok' => false, 'error' => 'Calendar is read-only (shared or system). Only your own calendars can be modified.'];
        }
        $uri = $parts[1] ?? '';
        $raw = $this->backend->getCalendarObject((int)$cal['id'], $uri);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'Event not found'];
        }
        try {
            $this->backend->deleteCalendarObject((int)$cal['id'], $uri);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Calendar delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted event ' . $id];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function listTasks(string $userId, array $args = []): array {
        $cals = $this->calendars($userId);
        if ($cals === []) {
            return ['ok' => false, 'error' => 'No calendar found for this user'];
        }
        // Status-Filter (Kommagetrennt oder Array; z.B. "needs-action,in-process").
        $statusFilter = [];
        if (isset($args['status'])) {
            $st = $args['status'];
            if (is_array($st)) {
                $statusFilter = array_map(static fn($s) => strtolower(trim((string)$s)), $st);
            } else {
                $statusFilter = array_filter(array_map(static fn($s) => strtolower(trim($s)), explode(',', (string)$st)));
            }
            $statusFilter = array_values(array_unique($statusFilter));
        }
        // Kategorie-Filter.
        $catFilter = [];
        if (isset($args['category'])) {
            $cats = $args['category'];
            if (is_array($cats)) {
                $catFilter = array_map(static fn($c) => strtolower(trim((string)$c)), $cats);
            } else {
                $catFilter = array_filter(array_map(static fn($c) => strtolower(trim($c)), explode(',', (string)$cats)));
            }
            $catFilter = array_values(array_unique($catFilter));
        }
        // Overdue-only: nur Aufgaben mit DUE < jetzt (in der Nutzer-Zeitzone).
        $overdueOnly = !empty($args['overdue_only']);
        $now = new \DateTimeImmutable('now', $this->userTimeZone($userId));
        $out = [];
        foreach ($cals as $c) {
            foreach ($this->backend->getCalendarObjects((int)$c['id']) as $obj) {
                if (strtolower((string)($obj['component'] ?? '')) !== 'vtodo') {
                    continue;
                }
                $raw = $this->backend->getCalendarObject((int)$c['id'], (string)$obj['uri']);
                if (!isset($raw['calendardata'])) {
                    continue;
                }
                try {
                    $v = Reader::read((string)$raw['calendardata']);
                } catch (\Throwable $e) {
                    continue;
                }
                foreach ($v->VTODO as $vt) {
                    $status = strtolower((string)($vt->STATUS ?? ''));
                    if ($statusFilter !== [] && !in_array($status, $statusFilter, true)) {
                        continue;
                    }
                    if ($catFilter !== []) {
                        $catStr = strtolower((string)($vt->CATEGORIES ?? ''));
                        $catList = $catStr === '' ? [] : array_map('trim', explode(',', $catStr));
                        if (count(array_intersect($catFilter, $catList)) === 0) {
                            continue;
                        }
                    }
                    $due = $vt->DUE ? $vt->DUE->getDateTime() : null;
                    if ($overdueOnly && ($due === null || $due >= $now || $status === 'completed')) {
                        continue;
                    }
                    $out[] = [
                        'id' => $c['uri'] . '/' . $obj['uri'],
                        'calendar' => (string)$c['displayname'],
                        'title' => (string)($vt->SUMMARY ?? ''),
                        'status' => (string)($vt->STATUS ?? ''),
                        'due' => $due ? $due->format('Y-m-d\TH:i:s') : null,
                        'priority' => (int)(string)($vt->PRIORITY ?? 0),
                        'description' => (string)($vt->DESCRIPTION ?? ''),
                        'categories' => $vt->CATEGORIES ? array_map('trim', explode(',', (string)$vt->CATEGORIES)) : [],
                        'overdue' => $due !== null && $due < $now && $status !== 'completed',
                    ];
                }
            }
        }
        $statuses = ['needs-action' => 0, 'in-process' => 1, 'completed' => 2, 'cancelled' => 3];
        usort($out, static function ($a, $b) use ($statuses) {
            $sa = $statuses[strtolower((string)$a['status'])] ?? 0;
            $sb = $statuses[strtolower((string)$b['status'])] ?? 0;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return (string)$a['due'] <=> (string)$b['due'];
        });
        return ['ok' => true, 'result' => ['tasks' => $out]];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function createTask(string $userId, array $args): array {
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'Task title required'];
        }
        $cal = $this->resolveCalendar($userId, (string)($args['calendar'] ?? ''), true);
        if ($cal === null) {
            $cal = $this->resolveCalendar($userId, null, true);
        }
        if ($cal === null) {
            return ['ok' => false, 'error' => 'No writable calendar found. Create tasks only in your own calendars.'];
        }
        $allDay = false;
        $due = $this->parseTime((string)($args['due'] ?? ''), $userId, $allDay);
        $uid = 'ai-task-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $vc = new VCalendar();
        $vt = $vc->add('VTODO');
        $vt->add('UID', $uid);
        $vt->add('DTSTAMP', new \DateTimeImmutable('now'));
        $vt->add('SUMMARY', $title);
        $vt->add('STATUS', (string)($args['status'] ?? 'NEEDS-ACTION'));
        if ($due !== null) {
            if ($allDay) {
                $vt->add('DUE', $due->format('Ymd'));
            } else {
                $vt->add('DUE', $due->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            }
        }
        if (($args['priority'] ?? 0) !== 0) {
            $vt->add('PRIORITY', (int)$args['priority']);
        }
        if (($args['description'] ?? '') !== '') {
            $vt->add('DESCRIPTION', (string)$args['description']);
        }
        if (($args['categories'] ?? '') !== '') {
            $vt->add('CATEGORIES', (string)$args['categories']);
        }
        try {
            $this->backend->createCalendarObject((int)$cal['id'], $uid . '.ics', $vc->serialize());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Task write failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['task' => ['id' => $cal['uri'] . '/' . $uid . '.ics', 'title' => $title, 'calendar' => (string)$cal['displayname']]]];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function updateTask(string $userId, array $args): array {
        $id = trim((string)($args['task_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'task_id required (use the id from list_tasks)'];
        }
        $parts = explode('/', $id, 2);
        $cal = $this->resolveCalendar($userId, $parts[0] ?? '');
        if ($cal === null) {
            return ['ok' => false, 'error' => 'Calendar not found'];
        }
        if (!$this->calendarWritable($cal, $userId)) {
            return ['ok' => false, 'error' => 'Calendar is read-only (shared or system). Only your own calendars can be modified.'];
        }
        $uri = $parts[1] ?? '';
        $raw = $this->backend->getCalendarObject((int)$cal['id'], $uri);
        if ($raw === null || !isset($raw['calendardata'])) {
            return ['ok' => false, 'error' => 'Task not found'];
        }
        try {
            $v = Reader::read((string)$raw['calendardata']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not parse task: ' . $e->getMessage()];
        }
        $vt = $v->VTODO ? $v->VTODO[0] : null;
        if ($vt === null) {
            return ['ok' => false, 'error' => 'No VTODO found'];
        }
        if (isset($args['title']) && (string)$args['title'] !== '') {
            $vt->SUMMARY = (string)$args['title'];
        }
        if (array_key_exists('status', $args)) {
            $status = strtoupper((string)$args['status']);
            $allowed = ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'];
            if (in_array($status, $allowed, true)) {
                $vt->STATUS = $status;
                if ($status === 'COMPLETED') {
                    $vt->{'PERCENT-COMPLETE'} = 100;
                }
            }
        }
        if (array_key_exists('due', $args)) {
            $vt->remove('DUE');
            if (trim((string)$args['due']) !== '') {
                $allDay = false;
                $due = $this->parseTime((string)$args['due'], $userId, $allDay);
                if ($due !== null) {
                    $vt->add('DUE', $allDay ? $due->format('Ymd') : $due->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
                }
            }
        }
        if (array_key_exists('description', $args)) {
            if ((string)$args['description'] === '') {
                unset($vt->DESCRIPTION);
            } else {
                $vt->DESCRIPTION = (string)$args['description'];
            }
        }
        if (array_key_exists('categories', $args)) {
            if (trim((string)$args['categories']) === '') {
                unset($vt->CATEGORIES);
            } else {
                $vt->CATEGORIES = (string)$args['categories'];
            }
        }
        if (array_key_exists('priority', $args)) {
            $prio = max(0, min(9, (int)$args['priority']));
            if ($prio === 0) {
                unset($vt->PRIORITY);
            } else {
                $vt->PRIORITY = $prio;
            }
        }
        try {
            $this->backend->updateCalendarObject((int)$cal['id'], $uri, $v->serialize());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Task update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['task' => ['id' => $id, 'title' => (string)$vt->SUMMARY, 'status' => (string)($vt->STATUS ?? '')]]];
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    public function deleteTask(string $userId, array $args): array {
        $id = trim((string)($args['task_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'task_id required'];
        }
        $parts = explode('/', $id, 2);
        $cal = $this->resolveCalendar($userId, $parts[0] ?? '');
        if ($cal === null) {
            return ['ok' => false, 'error' => 'Calendar not found'];
        }
        if (!$this->calendarWritable($cal, $userId)) {
            return ['ok' => false, 'error' => 'Calendar is read-only (shared or system). Only your own calendars can be modified.'];
        }
        $uri = $parts[1] ?? '';
        $raw = $this->backend->getCalendarObject((int)$cal['id'], $uri);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'Task not found'];
        }
        try {
            $this->backend->deleteCalendarObject((int)$cal['id'], $uri);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Task delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted task ' . $id];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function completeTask(string $userId, array $args): array {
        return $this->updateTask($userId, array_merge($args, ['status' => 'COMPLETED']));
    }

    /**
     * Findet freie Zeitfenster (Slots) im Kalender des Nutzers.
     * Parameter: days (1-30), min_minutes (default 30), workday_start/end "HH:MM".
     * Liefert die ersten N Lücken >= min_minutes innerhalb der täglichen Arbeitszeit.
     * @return array{ok:true,result:array}|array{ok:false,error:string}
     */
    public function findFreeSlots(string $userId, array $args = []): array {
        $days = isset($args['days']) ? max(1, min(30, (int)$args['days'])) : 7;
        $minMinutes = isset($args['min_minutes']) ? max(5, min(480, (int)$args['min_minutes'])) : 30;
        $tz = $this->userTimeZone($userId);
        $today = new \DateTimeImmutable('today', $tz);
        // 09:00–18:00 default work hours.
        $workStart = $this->parseHHMM((string)($args['workday_start'] ?? '09:00'));
        $workEnd = $this->parseHHMM((string)($args['workday_end'] ?? '18:00'));
        if ($workStart === null) { $workStart = [9, 0]; }
        if ($workEnd === null || $workEnd <= $workStart) { $workEnd = [18, 0]; }
        $cal = $this->resolveCalendar($userId, (string)($args['calendar'] ?? ''));
        $cals = $cal !== null ? [$cal] : $this->calendars($userId);
        if ($cals === []) {
            return ['ok' => false, 'error' => 'No calendar found for this user'];
        }
        // Sammle alle VEvents im Zeitraum.
        $rangeStart = $today;
        $rangeEnd = $today->modify('+' . $days . ' days');
        $busy = [];
        foreach ($cals as $c) {
            foreach ($this->backend->getCalendarObjects((int)$c['id']) as $obj) {
                if (strtolower((string)($obj['component'] ?? '')) !== 'vevent') {
                    continue;
                }
                $raw = $this->backend->getCalendarObject((int)$c['id'], (string)$obj['uri']);
                if (!isset($raw['calendardata'])) { continue; }
                try {
                    $v = Reader::read((string)$raw['calendardata']);
                } catch (\Throwable $e) { continue; }
                foreach ($this->expandEvents($v, $rangeStart, $rangeEnd, $userId) as $occurrence) {
                    $ve = $occurrence['event'];
                    $dtstart = $occurrence['start'];
                    $dtend = $occurrence['end'] ?? $dtstart->modify('+1 hour');
                    if ($occurrence['all_day']) {
                        // Ganztages-Termine blockieren den ganzen Tag.
                        $busy[] = [
                            (new \DateTimeImmutable($dtstart->format('Y-m-d') . ' 00:00:00', $tz))->getTimestamp(),
                            (new \DateTimeImmutable($dtstart->format('Y-m-d') . ' 23:59:59', $tz))->getTimestamp(),
                        ];
                    } else {
                        $busy[] = [
                            $dtstart->setTimezone($tz)->getTimestamp(),
                            $dtend->setTimezone($tz)->getTimestamp(),
                        ];
                    }
                }
            }
        }
        // Suche Slot je Tag im Arbeitsfenster.
        $slots = [];
        for ($d = 0; $d < $days && count($slots) < 10; $d++) {
            $day = $today->modify('+' . $d . ' days');
            $winStart = $day->setTime($workStart[0], $workStart[1]);
            $winEnd = $day->setTime($workEnd[0], $workEnd[1]);
            $cursor = $winStart->getTimestamp();
            $winEndTs = $winEnd->getTimestamp();
            // Sortiere busy nach start.
            $dayBusy = array_filter($busy, static function ($b) use ($winStart, $winEnd) {
                return $b[1] > $winStart->getTimestamp() && $b[0] < $winEnd->getTimestamp();
            });
            usort($dayBusy, static fn($a, $b) => $a[0] <=> $b[0]);
            foreach ($dayBusy as [$bs, $be]) {
                if ($bs > $cursor && ($bs - $cursor) >= $minMinutes * 60) {
                    $slots[] = [
                        'start' => date('Y-m-d\TH:i:s', $cursor),
                        'end' => date('Y-m-d\TH:i:s', $bs),
                        'minutes' => (int)(($bs - $cursor) / 60),
                        'day' => $day->format('Y-m-d'),
                    ];
                    if (count($slots) >= 10) { break 2; }
                }
                $cursor = max($cursor, $be);
                if ($cursor >= $winEndTs) { break; }
            }
            if ($cursor < $winEndTs && ($winEndTs - $cursor) >= $minMinutes * 60) {
                $slots[] = [
                    'start' => date('Y-m-d\TH:i:s', $cursor),
                    'end' => date('Y-m-d\TH:i:s', $winEndTs),
                    'minutes' => (int)(($winEndTs - $cursor) / 60),
                    'day' => $day->format('Y-m-d'),
                ];
            }
        }
        return ['ok' => true, 'result' => ['slots' => $slots, 'count' => count($slots)]];
    }

    /** @return array{0:int,1:int}|null */
    private function parseHHMM(string $val): ?array {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($val), $m)) {
            $h = max(0, min(23, (int)$m[1]));
            $mi = max(0, min(59, (int)$m[2]));
            return [$h, $mi];
        }
        return null;
    }
}

