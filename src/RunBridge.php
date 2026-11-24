<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\job\models\CmsJobRun;
use Yii;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Operations of the executor for one run. The caller has authenticated the run's
 * REST token and holds the chat row lock; every call presents the cms-job lease.
 */
final class RunBridge
{
    public const OPS = ['agentStart', 'agentEvent', 'agentClaimWrite', 'agentWriteResult', 'agentFinish'];

    /** Messages sent during the run, handed to the executor once; marks them delivered. */
    private static function notes(array $run, array &$state): array
    {
        $notes = ChatApi::undelivered($run);
        if ($notes) {
            $state['delivered'] = (int)end($notes)['id'];
            Yii::$app->db->createCommand()->update(ChatApi::RUN, ['state' => json_encode($state, JSON_THROW_ON_ERROR)], ['id' => $run['id']])->execute();
        }
        return array_column($notes, 'text');
    }

    /** Token usage reported by the executor for the whole run, with a reference cost. */
    private static function saveUsage(array $run, $usage): void
    {
        if (!is_array($usage)) {
            return;
        }
        $int = static fn($v) => max(0, min(PHP_INT_MAX, (int)$v));
        $input = $int($usage['input'] ?? 0);
        $cached = min($input, $int($usage['cached'] ?? 0));
        $output = $int($usage['output'] ?? 0);
        $model = ModelCatalog::resolve((string)$run['engine'], is_string($usage['model'] ?? null) ? trim($usage['model']) : '');
        $engineCost = !empty($usage['costKnown']) && is_numeric($usage['costUsd'] ?? null) ? (float)$usage['costUsd'] : null;
        [$usd, $source] = ModelCatalog::estimate($model, $input, $cached, $output, $engineCost);
        Yii::$app->db->createCommand()->update(ChatApi::RUN, [
            'model' => $model !== '' ? $model : null,
            'tokens_input' => $input, 'tokens_cached' => $cached, 'tokens_output' => $output,
            'model_calls' => $int($usage['calls'] ?? 0), 'tool_calls' => $int($usage['tools'] ?? 0),
            'cost_usd' => $usd, 'cost_source' => $source,
        ], ['id' => $run['id']])->execute();
    }

    public static function handle(string $op, array $chat, array $data): array
    {
        $run = (new Query())->from(ChatApi::RUN)->where(['id' => (int)($data['run'] ?? 0), 'chat_id' => $chat['id']])->one();
        $job = $run && $run['job_run_id'] ? CmsJobRun::findOne((int)$run['job_run_id']) : null;
        if (!$run || !$job || $job->job_type !== ChatApi::JOB_TYPE || $job->status !== CmsJobRun::STATUS_RUNNING
            || !$job->execution_token || !hash_equals((string)$job->execution_token, (string)($data['lease'] ?? ''))
            || (int)$run['created_by'] !== (int)$chat['owner_id'] || (int)$run['created_by'] !== (int)Yii::$app->user->id
            || (int)$job->lease_until < time()) {
            throw new ForbiddenHttpException();
        }
        if (!in_array($run['status'], ChatApi::ACTIVE, true)) {
            return ['done' => true];
        }
        $db = Yii::$app->db;
        $state = json_decode($run['state'], true) ?: [];
        $cancel = (bool)$run['cancel_requested_at'];
        switch ($op) {
            case 'agentStart':
                if ($run['status'] === 'queued') {
                    $db->createCommand()->update(ChatApi::RUN, ['status' => 'running', 'updated_at' => time()], ['id' => $run['id']])->execute();
                    ChatApi::event($run, 'started', 'Начинаю работу.');
                }
                $message = (new Query())->from(ChatApi::MESSAGE)->where(['chat_id' => $chat['id'], 'id' => $run['message_id']])->select('text')->scalar();
                return [
                    'message' => $message, 'messageId' => (int)$run['message_id'],
                    'confirmed' => json_decode($run['confirmation'] ?? 'null', true), 'state' => (object)$state, 'cancel' => $cancel,
                ];
            case 'agentEvent':
                $text = (string)($data['text'] ?? '');
                $type = (string)($data['type'] ?? 'step');
                if ($text === '' || !preg_match('/^[a-z_]{1,24}$/D', $type)) {
                    throw new BadRequestHttpException();
                }
                ChatApi::event($run, $type, $text);
                // Only an executor that declares it reads the notes gets them; otherwise they start a follow-up run.
                return ['cancel' => $cancel, 'notes' => empty($data['acceptsNotes']) ? [] : self::notes($run, $state)];
            case 'agentClaimWrite':
                if ($cancel) {
                    return ['claimed' => false, 'cancel' => true];
                }
                if (!empty($state['claimed'])) {
                    return ['claimed' => false];
                }
                $state['claimed'] = true;
                break;
            case 'agentWriteResult':
                $state['outcome'] = $data['outcome'] ?? null;
                break;
            case 'agentFinish':
                $screenshots = [];
                $files = $data['screenshots'] ?? [];
                if (!is_array($files) || count($files) > 4) {
                    throw new BadRequestHttpException();
                }
                foreach ($files as $file) {
                    $bytes = base64_decode((string)($file['base64'] ?? ''), true);
                    if (!$bytes || strlen($bytes) > 1000000 || substr($bytes, 0, 3) !== "\xff\xd8\xff"
                        || !preg_match('/^preview-[0-9]+-(phone|desktop)\.jpg$/D', (string)($file['name'] ?? ''))) {
                        throw new BadRequestHttpException();
                    }
                    $screenshots[] = ['name' => $file['name'], 'bytes' => $bytes];
                }
                $pending = isset($data['pending']) && is_array($data['pending']) ? $data['pending'] : null;
                $status = !empty($data['canceled']) ? 'canceled' : (!empty($data['failed']) ? 'failed' : 'succeeded');
                $text = mb_substr((string)($data['text'] ?? ''), 0, 6000) ?: 'Готово.';
                $record = $data['record'] ?? null;
                if (is_array($record) && ($link = ChatApi::recordLink((string)($record['tool'] ?? ''), (int)($record['id'] ?? 0)))
                    && !str_contains($text, $link['url'])) {
                    $text .= "\n\n[" . $link['label'] . '](' . $link['url'] . ')';
                }
                self::saveUsage($run, $data['usage'] ?? null);
                ChatApi::finish($run, $status, $text, $pending, $screenshots);
                // A message sent during the run that the executor never read starts the next run.
                if ($status === 'succeeded' && !$pending) {
                    ChatApi::followUp(ChatApi::findRun((int)$run['id']));
                }
                return ['done' => true];
            default:
                throw new BadRequestHttpException();
        }
        $db->createCommand()->update(ChatApi::RUN, ['state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => time()], ['id' => $run['id']])->execute();
        return $state;
    }
}
