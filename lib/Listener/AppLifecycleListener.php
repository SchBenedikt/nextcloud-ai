<?php
declare(strict_types=1);
namespace OCA\EvaAi\Listener;
use OCA\EvaAi\BackgroundJob\{BackgroundChatJob,ChatCleanupJob,IndexJob,ProactiveBriefingJob};
use OCA\EvaAi\Service\AppConfig;
use OCP\App\Events\{AppEnableEvent,AppUpdateEvent};
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\{Event,IEventListener};
use OCP\{IConfig,IUserManager};
final class AppLifecycleListener implements IEventListener {
 private const JOBS=[IndexJob::class,ChatCleanupJob::class,ProactiveBriefingJob::class,BackgroundChatJob::class];
 private const STATE=['index_running','index_started','index_heartbeat','index_finished','last_index_processed','last_index_total','last_index_error','last_index_cache_hits','last_index_cache_misses','last_index_ollama_requests','last_index_failed','index_config_hash','index_mode','index_cancel_requested','index_run_id','index_enrolled','knowledge_initialized','proactive_schedule_runs','search_revision','background_chat_queue','background_chat_history','learned_app_apis','learned_file_locations'];
 public function __construct(private IConfig $config,private IUserManager $users,private IJobList $jobs){}
 public function handle(Event $event):void {
  $id=$event instanceof AppEnableEvent?$event->getAppId():($event instanceof AppUpdateEvent?$event->getAppId():''); if($id!==AppConfig::APP)return;
  foreach(['background_chat_queue'=>'[]','background_chat_history'=>'[]','background_chat_users'=>'[]','index_scheduler_queue'=>'[]','index_scheduler_active'=>'{}','index_job_running'=>'0','index_job_stop_requested'=>'1','index_reset_requested'=>'1'] as $k=>$v)$this->config->setAppValue(AppConfig::APP,$k,$v);
  $this->users->callForAllUsers(function($u):void{$uid=(string)$u->getUID();foreach(self::STATE as $k){try{$this->config->deleteUserValue($uid,AppConfig::APP,$k);}catch(\Throwable){}}try{$this->config->setUserValue($uid,AppConfig::APP,'index_cancel_requested','1');}catch(\Throwable){}});
  // Do not delete large EVA tables in the enable/update request. On a busy
  // instance that acquires long DB locks and makes Nextcloud appear frozen.
  // IndexJob performs the same complete reset as its first isolated cron tick.
  foreach(self::JOBS as $j){try{$this->jobs->remove($j);$this->jobs->add($j);}catch(\Throwable){}}
 }
}
