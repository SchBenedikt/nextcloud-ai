<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Executes the tools owned by one integration domain. */
interface DomainToolExecutor {
	/** @return list<string> */
	public function tools(): array;

	/** @return array{ok:bool,result?:mixed,error?:string} */
	public function execute(string $tool, string $userId, array $args): array;
}
