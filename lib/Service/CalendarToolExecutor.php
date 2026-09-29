<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use LogicException;

final class CalendarToolExecutor implements DomainToolExecutor {
	private const TOOLS = [
		'list_calendars', 'list_calendar_events', 'create_calendar_event', 'update_calendar_event',
		'delete_calendar_event', 'find_free_slots', 'list_tasks', 'create_task',
		'update_task', 'complete_task', 'delete_task',
	];

	public function __construct(private CalendarService $calendar) {
	}

	public function tools(): array {
		return self::TOOLS;
	}

	public function execute(string $tool, string $userId, array $args): array {
		return match ($tool) {
			'list_calendars' => ['ok' => true, 'result' => $this->calendar->calendars($userId)],
			'list_calendar_events' => $this->calendar->listEvents($userId, $args),
			'create_calendar_event' => $this->calendar->createEvent($userId, $args),
			'update_calendar_event' => $this->calendar->updateEvent($userId, $args),
			'delete_calendar_event' => $this->calendar->deleteEvent($userId, $args),
			'find_free_slots' => $this->calendar->findFreeSlots($userId, $args),
			'list_tasks' => $this->calendar->listTasks($userId, $args),
			'create_task' => $this->calendar->createTask($userId, $args),
			'update_task' => $this->calendar->updateTask($userId, $args),
			'complete_task' => $this->calendar->completeTask($userId, $args),
			'delete_task' => $this->calendar->deleteTask($userId, $args),
			default => throw new LogicException('Unsupported calendar tool: ' . $tool),
		};
	}
}
