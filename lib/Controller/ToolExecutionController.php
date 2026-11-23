<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\ConfirmToolRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Authenticated execution boundary for explicitly confirmed web-chat tools. */
final class ToolExecutionController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ChatStore $chatStore,
		private ActionExecutor $executor,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function confirmTool(): DataResponse {
		$user = $this->userId !== null && $this->userId !== '' ? $this->userId : null;
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$request = ConfirmToolRequest::fromArray([
				'name' => $this->requestParam('name'),
				'arguments' => $this->requestParam('arguments'),
				'args' => $this->requestParam('args', []),
				'chatId' => $this->requestParam('chatId'),
				'confirmationToken' => $this->requestParam('confirmationToken'),
			]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}

		// Claim before execution so duplicate submissions cannot repeat mutations.
		if ($request->chatId !== null && $request->confirmationToken !== null) {
			$claim = $this->chatStore->claimConfirmation($user, $request->chatId, $request->confirmationToken);
			if ($claim === 'already') {
				return new ErrorDataResponse([
					'ok' => false,
					'alreadyProcessed' => true,
					'error' => 'This action was already processed - reload the chat to see its result.',
				], 409);
			}
		}

		$this->executor->setSurface(ToolPolicy::SURFACE_WEB);
		// Tool failures stay structured over HTTP 200 so clients persist the
		// consumed confirmation instead of offering a retry that cannot succeed.
		return new ErrorDataResponse($this->executor->runConfirmed($user, $request->name, $request->arguments));
	}
}
