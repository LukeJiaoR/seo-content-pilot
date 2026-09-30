<?php
/**
 * LLM client contract.
 *
 * @package SCP
 */

namespace SCP\LLM;

/**
 * Common interface for provider clients.
 */
interface LLM_Client {

	/**
	 * Run a chat completion.
	 *
	 * @param array $messages OpenAI-style messages: [{role, content}, ...].
	 * @param array $opts     Options: model, temperature, max_tokens, json (bool).
	 * @return array{text:string,prompt_tokens:int,completion_tokens:int,model:string}
	 *
	 * @throws LLM_Exception On unrecoverable API errors.
	 */
	public function complete( array $messages, array $opts = array() ): array;

	/**
	 * Provider id, e.g. "openai".
	 *
	 * @return string
	 */
	public function get_provider(): string;
}
