<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationChunkRepository,VectorGenerationMutationRepository,VectorGenerationRepository,VectorOutboxRepository,VectorSourceDeletion};
use OCA\EvaAi\Dto\PreparedVectorChunk;
use OCA\EvaAi\Service\AppConfig;
use Psr\Log\LoggerInterface;

/** Rebuilds changed documents for the active generation without blocking indexing or chat. */
final class GenerationMutationWorker {
    private const MAX_CHUNKS = 256;
    private const MAX_SECONDS = 75;

    public function __construct(
        private VectorGenerationMutationRepository $mutations,
        private VectorGenerationRepository $generations,
        private GenerationChunkRepository $chunks,
        private VectorSourceDeletion $deletion,
        private GenerationDocumentPreparer $preparer,
        private GenerationEmbeddingProvider $embeddings,
        private VectorOutboxRepository $outbox,
        private VectorSourceAccess $access,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {}

    /** One bounded lease, up to 256 chunks and 75 seconds. */
    public function run(): array {
        $claim = $this->mutations->claim(time());
        $stats = ['claimed'=>$claim !== null,'chunks'=>0,'completed'=>false,'error'=>null];
        if ($claim === null) return $stats;

        $previousUser = $this->config->userId();
        $deadline = microtime(true) + self::MAX_SECONDS;
        try {
            $this->config->setUserId($claim['user_id']);
            $this->mutations->assertLease($claim,time());
            $generation = $this->generations->find($claim['user_id'],$claim['generation_id'])
                ?? throw new VectorStoreException('generation_missing');
            if ($generation->backend !== 'sql' && $generation->backend !== 'qdrant') throw new VectorStoreException('generation_backend_invalid');
            $target = $this->generations->target($claim['user_id'],$claim['generation_id'])
                ?? throw new VectorStoreException('generation_unprepared');
            $this->access->assertOwner($claim['user_id']);
            $source = $this->chunks->snapshot($claim['user_id'],$claim['document_id']);

            if ($source === null) {
                // A delete may race a claimed upsert. Canonical deletion removes
                // old rows; this guarded cleanup also catches an interrupted race.
                $this->deletion->deleteDocuments([$claim['document_id']],false,true,$generation->id);
                $this->outbox->transaction(fn() => $this->mutations->complete($claim));
                $stats['completed'] = true;
                return $stats;
            }

            $guard = function () use ($claim,$generation,$target,$deadline): void {
                if (microtime(true) >= $deadline) throw new VectorStoreException('mutation_budget_exceeded');
                $this->mutations->assertLease($claim,time());
                if ($this->generations->status($generation->userId,$generation->id) !== 'active'
                    || $this->generations->target($generation->userId,$generation->id) !== $target) {
                    throw new VectorStoreException('generation_state_changed');
                }
            };
            $plan = $this->preparer->prepare($generation,$source,$guard);

            $cursor = $claim['chunk_cursor'];
            $samePlan = $claim['source_stamp'] === $source->stamp && $claim['plan_hash'] === $plan->planHash;
            if ($cursor > 0 && !$samePlan) {
                $this->deletion->deleteDocuments([$source->id],false,true,$generation->id);
                $cursor = 0;
            }
            if (!$samePlan || $cursor === 0) {
                $this->outbox->transaction(fn() => $this->mutations->checkpoint($claim,0,$plan->planHash,$source->stamp));
            }
            $upper = min(count($plan->chunks),$cursor+self::MAX_CHUNKS);
            for ($offset=$cursor; $offset<$upper; $offset+=8) {
                if (microtime(true) >= $deadline-35) {
                    $this->mutations->yield($claim);
                    $stats['yielded'] = true;
                    return $stats;
                }
                $guard();
                $batch = array_slice($plan->chunks,$offset,min(8,$upper-$offset));
                $this->mutations->heartbeat($claim,time());
                $batchVectors = $batch === [] ? [] : $this->embeddings->embed($generation,array_column($batch,'content'),$guard,true,
                    min(30,max(1,(int)ceil($deadline-microtime(true)))));
                $guard();
                foreach ($batch as $index=>$chunk) {
                    $this->chunks->putActiveChunk($generation,$target,$source,
                        new PreparedVectorChunk($offset+$index,$chunk['content'],$batchVectors[$index],$chunk['tokens'],$chunk['provenance']));
                    $stats['chunks']++;
                }
                $this->outbox->transaction(fn() => $this->mutations->checkpoint($claim,$offset+count($batch),$plan->planHash,$source->stamp));
            }
            $guard();
            if ($upper < count($plan->chunks)) {
                $this->mutations->yield($claim);
                $stats['yielded'] = true;
                return $stats;
            }
            $this->outbox->transaction(fn() => $this->mutations->complete($claim));
            $stats['completed'] = true;
            return $stats;
        } catch (\Throwable $error) {
            $reason = $error instanceof VectorStoreException ? $error->reason : 'mutation_failed';
            $stats['error'] = $reason;
            try { $this->mutations->release($claim,$reason); }
            catch (\Throwable) { /* Expired or superseded leases are recovered by the next claim. */ }
            try { $this->logger->warning('eva_ai: active generation mutation failed',['reason'=>$reason]); }
            catch (\Throwable) {}
            return $stats;
        } finally {
            $this->config->setUserId($previousUser);
        }
    }
}
