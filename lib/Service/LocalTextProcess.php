<?php
declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Trusted local converters only; argv avoids shell interpolation. */
class LocalTextProcess {
    public function run(array $arguments,float $seconds=20,int $maxBytes=8388608): string {
        if ($arguments===[] || !is_string($arguments[0]) || !str_starts_with($arguments[0],'/')
            || $seconds<0.05 || $seconds>60 || $maxBytes<1 || $maxBytes>8388608) throw new \InvalidArgumentException('Invalid text conversion budget.');
        // GNU timeout additionally terminates converter descendants where
        // available. The native deadline remains the portable fallback.
        if (is_executable('/usr/bin/timeout')) $arguments=['/usr/bin/timeout','--kill-after=1s',(string)$seconds.'s',...$arguments];
        $process=proc_open($arguments,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start text converter.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $deadline=microtime(true)+$seconds+2;$output='';
        try {
            while(true) {
                $piece=stream_get_contents($pipes[1],65536);if($piece!==false)$output.=$piece;
                stream_get_contents($pipes[2],65536);
                if(strlen($output)>$maxBytes || microtime(true)>=$deadline) throw new \RuntimeException('Text converter output or runtime limit exceeded.');
                $status=proc_get_status($process);
                if(!$status['running']) {
                    $remaining=stream_get_contents($pipes[1],max(1,$maxBytes-strlen($output)+1));if($remaining!==false)$output.=$remaining;
                    if($status['exitcode']!==0 || strlen($output)>$maxBytes) throw new \RuntimeException('Text conversion failed or exceeded its budget.');
                    return $output;
                }
                usleep(10000);
            }
        } finally {
            if(proc_get_status($process)['running'])proc_terminate($process,9);
            fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        }
    }
}
