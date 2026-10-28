<template>
<div class="settings-group">
<section id="settings-safety" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Safety & actions') }}</h3>
						<p>{{ $t('Choose what EVA may do beyond answering questions. Recommended defaults are shown below.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="actionsEnabled" type="switch" class="native-toggle" :description="$t('Let EVA create, read, rename and search files, plus work with supported contacts and notes.')">{{ $t('Allow file actions') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="voiceInputEnabled" type="switch" class="native-toggle" :description="$t('Voice input uses your browser speech service to transcribe audio. Enable it only if you are comfortable with that service processing your audio.')">{{ $t('Enable voice input') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="backgroundActionsEnabled" type="switch" class="native-toggle" :disabled="actionsDisabled" :description="$t('When a chat continues after you close the page, EVA may execute requested changes without an open confirmation dialog.')">
					{{ $t('Allow background actions after I close the page') }}
				</NcCheckboxRadioSwitch>
				<div class="field" style="max-width: 360px; margin-top: 12px;">
					<NcTextField id="agent-max-tool-rounds" v-model="f.agent_max_tool_rounds" type="number" :label="$t('Maximum agent steps per request')" :disabled="actionsDisabled" />
					<p class="field-help">{{ $t('How many model/tool steps EVA may chain before it must summarize. Allowed range: 4–32; higher values help complex tasks but use more time and tokens.') }}</p>
				</div>
				<div class="warning-note" :class="{ 'is-disabled': actionsDisabled }">
					<strong>{{ actionsDisabled ? $t('Actions are disabled') : $t('Actions can change your files') }}</strong>
					<span>{{ actionsDisabled ? $t('The fields below are inactive until you enable file actions.') : $t('Complete, explicit requests run directly. EVA only asks when information is missing or a target is unclear.') }}</span>
				</div>

				<div class="field-grid" :class="{ 'is-disabled': actionsDisabled }">
					<div class="field">
						<NcTextField id="write-types" :label="$t('File types EVA may write')" v-model="f.exec_write_types" :disabled="actionsDisabled" :placeholder="$t('md, txt, csv')" />
						<p class="field-help">{{ $t('Comma-separated extensions, for example {types}. Empty means any supported text file.', { types: 'md, txt' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="write-max-chars" :label="$t('Maximum characters per file')" v-model="f.exec_write_max_chars" type="number" :disabled="actionsDisabled" />
						<p class="field-help">{{ $t('A size guard for files created or overwritten by EVA.') }}</p>
					</div>
				</div>

				<div class="choice-group" :class="{ 'is-disabled': actionsDisabled }">
					<strong class="choice-label">{{ $t('Delete permission') }}</strong>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="off" :disabled="actionsDisabled" :description="$t('EVA cannot delete files.')">
						{{ $t('Never') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="own" :disabled="actionsDisabled" :description="$t('EVA may delete files it created itself.')">
						{{ $t('Only EVA-created files') }} <em>{{ $t('Recommended') }}</em>
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="all" :disabled="actionsDisabled" class="choice-danger" :description="$t('Allows EVA to delete any file in your Files when you explicitly ask for it. Use with care.')">
						{{ $t('Any file in my Files') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcCheckboxRadioSwitch v-model="notificationsEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Uses Nextcloud Notifications when background or Talk work finishes.')">
					{{ $t('Notify me when a long answer is ready') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="learningEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Let EVA learn explicit preferences and facts from completed chats. You can edit or delete the personal knowledge below at any time.')">
					{{ $t('Learn from my conversations') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="safeCommandsEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Allow only explicitly confirmed, read-only diagnostics such as disk space and version checks. Shell scripts, pipes and file changes remain blocked.')">
					{{ $t('Allow safe local diagnostics') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="terminalCommandsEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Allow explicitly confirmed commands from the executable allowlist below. EVA never invokes a shell and rejects pipes, redirects and substitutions.')">
					{{ $t('Allow confirmed terminal commands') }}
				</NcCheckboxRadioSwitch>
				<p v-if="terminalCommandsEnabled" class="field-help">{{ $t('You can also ask EVA to run up to five allowlisted commands sequentially; each command is shown and timed separately. Confirmed interactive prompts may receive bounded stdin input and are closed with EOF.') }}</p>
				<NcCheckboxRadioSwitch v-if="terminalCommandsEnabled" v-model="terminalCommandAny" type="switch" class="native-toggle compact-switch" :description="$t('Let EVA run any executable after you confirm the exact command. Shell syntax is still blocked and no command runs without confirmation.')">
					{{ $t('Allow custom terminal executables') }}
				</NcCheckboxRadioSwitch>
				<div v-if="terminalCommandsEnabled" class="field-grid terminal-command-settings">
					<NcTextField v-if="!terminalCommandAny" id="terminal-command-allowlist" v-model="f.terminal_command_allowlist" :label="$t('Allowed terminal executables')" placeholder="date, uptime, php, git, ls" />
					<p class="field-help">{{ terminalCommandAny ? $t('Custom mode allows any executable path, but every exact command still requires confirmation and is limited to 30 seconds.') : $t('Comma-separated executable names or absolute paths. Every command still requires a confirmation dialog and is limited to 30 seconds.') }}</p>
				</div>
			</section>
<section id="settings-integrations" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Talk & notifications') }}</h3>
						<p>{{ $t('Configure how EVA behaves when she is used from Nextcloud Talk.') }}</p>
					</div>
				</div>
				<div class="field-grid">
					<div class="field">
						<NcTextField id="talk-history" :label="$t('Talk history size')" v-model="f.talk_history_size" type="number" />
						<p class="field-help">{{ $t('Number of recent Talk messages sent as context. Default: 50.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-trigger" :label="$t('Trigger name')" v-model="f.talk_bot_trigger" :placeholder="$t('Eva')" />
						<p class="field-help">{{ $t('The name people mention to address EVA in Talk. Example: {mention}.', { mention: '@Eva' }) }}</p>
					</div>
				</div>
				</section>
<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Web search') }}</h3>
						<p>{{ $t('Let EVA search the web when your indexed files cannot answer a question. DuckDuckGo works out of the box with no API key.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="userWebSearchEnabled" type="switch" class="native-toggle" :description="$t('Web search sends your question to an external search engine. The model only uses it as a fallback when your indexed files cannot answer.')">
					{{ $t('Enable web search') }}
				</NcCheckboxRadioSwitch>
				<div v-if="userWebSearchEnabled" class="admin-subsection">
					<div class="field">
						<label class="native-label" for="user-web-search-provider">{{ $t('Search provider') }}</label>
						<NcSelect input-id="user-web-search-provider" v-model="f.web_search_provider" :options="webSearchProviderOptions" label="label" :reduce="option => option.value" :input-label="$t('Search provider')" :label-outside="true" />
						<p class="field-help">{{ $t('News articles come from free news feeds and work with every provider; the web index is what differs. SearxNG, Brave and Tavily require the administrator to configure the URL or API key in the Eva AI admin settings.') }}</p>
					</div>
					<div class="field-grid field-grid-three">
						<div class="field"><NcTextField id="user-web-max" v-model="f.web_search_max_results" type="number" :label="$t('Results per search')" /><p class="field-help">{{ $t('How many search results are considered.') }}</p></div>
						<div class="field"><NcTextField id="user-web-timeout" v-model="f.web_search_timeout" type="number" :label="$t('Search timeout (seconds)')" /><p class="field-help">{{ $t('Maximum time for a provider request.') }}</p></div>
						<div class="field"><NcTextField id="user-web-candidates" v-model="f.web_search_candidates" type="number" :label="$t('Pages compared')" /><p class="field-help">{{ $t('Candidate pages read before ranking.') }}</p></div>
						<div class="field"><NcTextField id="user-web-content" v-model="f.web_search_content_chars" type="number" :label="$t('Search content limit')" /><p class="field-help">{{ $t('Maximum text returned per search result. Opening a page reads the full page.') }}</p></div>
						<div class="field"><NcTextField id="user-web-browser-timeout" v-model="f.web_search_browser_timeout" type="number" :label="$t('Browser timeout (seconds)')" /><p class="field-help">{{ $t('Maximum time for a rendered page.') }}</p></div>
					</div>
					<NcCheckboxRadioSwitch v-model="userWebSearchFetchContent" type="switch" class="native-toggle compact-switch">{{ $t('Read page content during searches') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchImages" type="switch" class="native-toggle compact-switch">{{ $t('Collect and show images') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchSafeSearch" type="switch" class="native-toggle compact-switch">{{ $t('Safe search') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchBrowser" type="switch" class="native-toggle compact-switch">{{ $t('Use the full browser for JavaScript pages') }}</NcCheckboxRadioSwitch>
				</div>
			</section>
</div>
</template>

<script>
import { inject } from 'vue'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
 name: 'ToolsSettings',
 components: { NcCheckboxRadioSwitch, NcSelect, NcTextField },
 setup() { return inject('eva-settings') },
}
</script>
