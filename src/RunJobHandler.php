<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\exceptions\JobTransientException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;
use Yii;
use yii\db\Query;

/**
 * cms-ai.run: hands one run to the executor and waits for it. The executor reports
 * progress and the result through the bridge; this handler only owns delivery.
 */
final class RunJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $job = $context->getRun();
        $runId = (int)($job->getPayload()['run'] ?? 0);
        $run = (new Query())->from(ChatApi::RUN)->where(['id' => $runId, 'job_run_id' => $job->id])->one();
        if (!$run) {
            throw new JobPermanentException('Поручение не найдено.');
        }
        if (!in_array($run['status'], ChatApi::ACTIVE, true)) {
            return;
        }
        $token = RunCredentials::decrypt($run['credential']);
        if ($token === null) {
            throw new JobPermanentException('Доступ поручения недоступен.');
        }
        $url = Yii::$app->params['cmsAi']['executorUrl'] ?? null;
        if (!$url) {
            throw new JobPermanentException('Исполнитель SkeekS AI не настроен.');
        }
        $reporter->setStage('agent', 'Выполнение поручения');
        $reporter->heartbeat();
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 850,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token,
                'X-Skeeks-AI-Bridge: ' . RunCredentials::key()],
            CURLOPT_POSTFIELDS => json_encode(['run' => $runId, 'chat' => (int)$run['chat_id'], 'lease' => $job->execution_token]),
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status >= 500) {
            throw new JobTransientException("Исполнитель временно недоступен ($status).");
        }
        try {
            if ($status !== 200 || empty(json_decode((string)$body, true)['done'])) {
                throw new JobPermanentException("Исполнитель отклонил поручение ($status).");
            }
        } finally {
            RunCredentials::revoke($token);
        }
    }
}
