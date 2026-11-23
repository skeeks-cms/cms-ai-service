<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\job\models\CmsJobRun;
use Yii;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Operations of SkeekS AI API v1 for the authenticated employee of this installation.
 * The site panel calls them in process; remote clients will reach the same operations over HTTPS.
 * Nothing here waits for the engine: a message becomes a run, the client reads its events.
 */
final class ChatApi
{
    public const CHAT = '{{%cms_ai_chat}}';
    public const MESSAGE = '{{%cms_ai_chat_message}}';
    public const ATTACHMENT = '{{%cms_ai_attachment}}';
    public const RUN = '{{%cms_ai_run}}';
    public const EVENT = '{{%cms_ai_run_event}}';
    public const JOB_TYPE = 'cms-ai.run';
    public const ACTIVE = ['queued', 'running'];
    public const DEFAULT_TITLE = 'Новый чат';

    /** Engines available to the employee: params cmsAi.engines = [engine => true | [user ids]]. */
    public static function engines(int $userId): array
    {
        $config = Yii::$app->params['cmsAi']['engines'] ?? ['codex' => true];
        $result = [];
        foreach ($config as $engine => $allowed) {
            if ($allowed === true || (is_array($allowed) && in_array($userId, array_map('intval', $allowed), true))) {
                $result[] = (string)$engine;
            }
        }
        return $result;
    }

    public static function scope(): array
    {
        return ['owner_id' => (int)Yii::$app->user->id, 'site_id' => (int)Yii::$app->skeeks->site->id];
    }

    /** Chat state for opening; without an ID the latest chat of the card context is chosen. */
    public static function chat(?int $id, ?int $company = null): array
    {
        $scope = self::scope();
        if ($id === null) {
            $id = (new Query())->from(self::CHAT)->where($scope + ['company_id' => $company ?: null])
                ->orderBy(['updated_at' => SORT_DESC, 'id' => SORT_DESC])->select('id')->scalar() ?: null;
        }
        $engines = self::engines($scope['owner_id']);
        if ($id === null) {
            return ['chatId' => null, 'title' => self::DEFAULT_TITLE, 'engine' => $engines[0] ?? null, 'engines' => $engines,
                'company' => $company ?: null, 'history' => [], 'files' => [], 'pending' => null, 'run' => null, 'events' => [], 'lastEventId' => 0];
        }
        $chat = self::find((int)$id);
        self::reconcile((int)$chat['id']);
        $chat = self::find((int)$id);
        $run = self::latestRun((int)$chat['id']);
        $events = $run && in_array($run['status'], self::ACTIVE, true) ? self::runEvents((int)$run['id']) : [];
        $steps = [];
        foreach ((new Query())->from(['e' => self::EVENT])->innerJoin(['r' => self::RUN], 'r.id = e.run_id')
            ->where(['e.chat_id' => $chat['id'], 'e.type' => ['tool', 'step']])
            ->andWhere(['not in', 'r.status', self::ACTIVE])
            ->select(['r.message_id', 'r.id', 'e.text'])->orderBy('e.id')->all() as $row) {
            $steps[$row['message_id']][] = $row['text'];
        }
        return [
            'chatId' => (int)$chat['id'],
            'title' => $chat['title'],
            'engine' => $chat['engine'],
            'engines' => $engines,
            'company' => $chat['company_id'] ? (int)$chat['company_id'] : null,
            'history' => (new Query())->from(self::MESSAGE)->where(['chat_id' => $chat['id']])
                ->select(['id', 'role', 'text'])->orderBy('id')->all(),
            'files' => (new Query())->from(['a' => self::ATTACHMENT])->innerJoin(['m' => self::MESSAGE], 'm.id = a.message_id')
                ->where(['m.chat_id' => $chat['id']])->select(['a.id', 'a.name', 'a.size', 'a.message_id'])->orderBy('a.id')->all(),
            'pending' => self::publicPending(json_decode($chat['pending'] ?? 'null', true)),
            'run' => $run ? self::runSummary($run) : null,
            'events' => $events,
            'steps' => (object)$steps,
            'lastEventId' => (int)((new Query())->from(self::EVENT)->where(['chat_id' => $chat['id']])->max('id') ?: 0),
        ];
    }

    /**
     * Stores the employee's message and queues its run. A repeated clientKey returns the
     * same run; a chat is created only together with its first message.
     *
     * @param array $files [['name' => string, 'bytes' => string]]
     */
    public static function send(array $data, array $files): array
    {
        $scope = self::scope();
        $text = trim((string)($data['message'] ?? ''));
        $clientKey = (string)($data['clientKey'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientKey)) {
            throw new BadRequestHttpException('Некорректный ключ сообщения.');
        }
        if (count($files) > 4) {
            throw new BadRequestHttpException('Можно прикрепить до четырёх файлов.');
        }
        if ($text === '' && $files) {
            $text = 'Изучи приложенные файлы.';
        }
        if ($text === '' || mb_strlen($text) > 1800) {
            throw new BadRequestHttpException('Введите поручение до 1800 символов.');
        }
        $requestKey = hash('sha256', 'message|' . $clientKey);
        $db = Yii::$app->db;
        [$runId, $created] = $db->transaction(function () use ($db, $data, $scope, $text, $files, $requestKey) {
            $chatId = isset($data['chatId']) ? (int)$data['chatId'] : 0;
            if ($chatId) {
                self::lock($chatId);
                $prior = (new Query())->from(self::RUN)->where(['chat_id' => $chatId, 'request_key' => $requestKey])->select('id')->scalar();
                if ($prior) {
                    return [(int)$prior, false];
                }
                $chat = self::find($chatId);
                self::reconcile($chatId);
                if ((new Query())->from(self::RUN)->where(['chat_id' => $chatId, 'status' => self::ACTIVE])->exists()) {
                    throw new ConflictHttpException('В этом чате уже выполняется поручение.');
                }
            } else {
                $engine = (string)($data['engine'] ?? '');
                if (!in_array($engine, self::engines($scope['owner_id']), true)) {
                    throw new ForbiddenHttpException('Движок недоступен для этой учётной записи.');
                }
                $company = (int)($data['company'] ?? 0) ?: null;
                $db->createCommand()->insert(self::CHAT, $scope + [
                    'engine' => $engine, 'title' => mb_substr($text, 0, 100), 'updated_at' => time(), 'company_id' => $company,
                ])->execute();
                $chatId = (int)$db->lastInsertID;
                $chat = self::find($chatId);
            }
            $db->createCommand()->insert(self::MESSAGE, [
                'chat_id' => $chatId, 'role' => 'user', 'text' => $text, 'created_at' => time(),
            ])->execute();
            $messageId = (int)$db->lastInsertID;
            $size = 0;
            foreach ($files as $file) {
                $name = (string)($file['name'] ?? '');
                $bytes = (string)($file['bytes'] ?? '');
                if (trim($name) === '' || mb_strlen($name) > 200 || preg_match('~[[:cntrl:]/\\\\]~', $name)
                    || ($size += strlen($bytes)) > 4194304) {
                    throw new BadRequestHttpException('Некорректное вложение.');
                }
                $db->createCommand()->insert(self::ATTACHMENT, [
                    'message_id' => $messageId, 'name' => $name, 'size' => strlen($bytes), 'content' => $bytes,
                ])->execute();
            }
            $changes = ['updated_at' => time(), 'pending' => null];
            if ($chat['title'] === self::DEFAULT_TITLE) {
                $changes['title'] = mb_substr($text, 0, 100);
            }
            $db->createCommand()->update(self::CHAT, $changes, ['id' => $chatId])->execute();
            return [self::createRun($chat, $messageId, $requestKey, null), true];
        });
        if ($created) {
            self::queue($runId);
        }
        return self::runSummary(self::findRun($runId)) + ['chatId' => (int)self::findRun($runId)['chat_id']];
    }

    /** Events after the client's cursor and the state of the latest run. */
    public static function events(int $chatId, int $after): array
    {
        self::find($chatId);
        self::reconcile($chatId);
        $events = (new Query())->from(self::EVENT)->where(['chat_id' => $chatId])->andWhere(['>', 'id', $after])
            ->select(['id', 'run_id', 'type', 'text', 'created_at'])->orderBy('id')->limit(200)->all();
        $run = self::latestRun($chatId);
        return ['events' => array_map([self::class, 'castEvent'], $events), 'run' => $run ? self::runSummary($run) : null];
    }

    /** Confirmation of the action shown in the chat; the executor gets it for this exact step. */
    public static function confirm(int $chatId, string $pendingId): array
    {
        $db = Yii::$app->db;
        [$runId, $created] = $db->transaction(function () use ($chatId, $pendingId, $db) {
            self::lock($chatId);
            $requestKey = hash('sha256', 'confirm|' . $pendingId);
            $prior = (new Query())->from(self::RUN)->where(['chat_id' => $chatId, 'request_key' => $requestKey])->select('id')->scalar();
            if ($prior) {
                return [(int)$prior, false];
            }
            $chat = self::find($chatId);
            $pending = json_decode($chat['pending'] ?? 'null', true);
            if (!$pending || ($pending['id'] ?? null) !== $pendingId) {
                throw new ConflictHttpException('Действие уже обработано.');
            }
            if ((new Query())->from(self::RUN)->where(['chat_id' => $chatId, 'status' => self::ACTIVE])->exists()) {
                throw new ConflictHttpException('В этом чате уже выполняется поручение.');
            }
            $messageId = (int)($pending['messageId'] ?? 0);
            $message = (new Query())->from(self::MESSAGE)->where(['chat_id' => $chatId, 'role' => 'user'])
                ->andWhere($messageId ? ['id' => $messageId] : [])->orderBy(['id' => SORT_DESC])->select('id')->scalar();
            if (!$message) {
                throw new ConflictHttpException('Поручение для этого действия не найдено.');
            }
            $db->createCommand()->update(self::CHAT, ['pending' => null, 'updated_at' => time()], ['id' => $chatId])->execute();
            return [self::createRun($chat, (int)$message, $requestKey, $pending), true];
        });
        if ($created) {
            self::queue($runId);
        }
        return self::runSummary(self::findRun($runId));
    }

    public static function reject(int $chatId, string $pendingId): void
    {
        Yii::$app->db->transaction(function () use ($chatId, $pendingId) {
            self::lock($chatId);
            $chat = self::find($chatId);
            $pending = json_decode($chat['pending'] ?? 'null', true);
            if (!$pending || ($pending['id'] ?? null) !== $pendingId) {
                throw new ConflictHttpException('Действие уже обработано.');
            }
            self::assistant($chatId, 'Действие отклонено, изменений не было.', null);
        });
    }

    /** Cooperative stop: a queued run is cancelled at once, a running one stops before its next step. */
    public static function cancel(int $chatId, int $runId): array
    {
        Yii::$app->db->transaction(function () use ($chatId, $runId) {
            self::lock($chatId);
            self::find($chatId);
            $run = (new Query())->from(self::RUN)->where(['id' => $runId, 'chat_id' => $chatId])->one();
            if (!$run || !in_array($run['status'], self::ACTIVE, true)) {
                return;
            }
            Yii::$app->db->createCommand()->update(self::RUN, ['cancel_requested_at' => time(), 'updated_at' => time()], ['id' => $runId])->execute();
            self::event($run, 'cancel', 'Запрошена остановка.');
            $job = $run['job_run_id'] ? CmsJobRun::findOne((int)$run['job_run_id']) : null;
            if ($run['status'] === 'queued') {
                if ($job) {
                    Yii::$app->jobs->cancel($job, Yii::$app->user->id);
                }
                self::finish($run, 'canceled', 'Поручение остановлено.', null);
            }
        });
        return self::runSummary(self::findRun($runId));
    }

    public static function file(int $chatId, int $fileId): array
    {
        self::find($chatId);
        $file = (new Query())->from(['a' => self::ATTACHMENT])->innerJoin(['m' => self::MESSAGE], 'm.id = a.message_id')
            ->where(['m.chat_id' => $chatId, 'a.id' => $fileId])->select(['a.name', 'a.content'])->one();
        if (!$file) {
            throw new NotFoundHttpException();
        }
        return $file;
    }

    /** Link to the record an action created or changed, in this installation's administration. */
    public static function recordLink(string $tool, int $id): ?array
    {
        $routes = [
            'cms_ai_task_' => ['/cms/admin-cms-task/view', 'Открыть задачу №'],
            'cms_task_' => ['/cms/admin-cms-task/view', 'Открыть задачу №'],
            'cms_company_' => ['/cms/admin-cms-company/view', 'Открыть компанию №'],
            'cms_deal_' => ['/cms/admin-cms-deal/view', 'Открыть услугу №'],
            'cms_tree_' => ['/cms/admin-tree/update', 'Открыть раздел №'],
            'page_css_' => ['/cms/admin-tree/update', 'Открыть раздел №'],
        ];
        if ($id <= 0) {
            return null;
        }
        foreach ($routes as $prefix => [$route, $label]) {
            if (str_starts_with($tool, $prefix)) {
                $url = \skeeks\cms\backend\helpers\BackendUrlHelper::createByParams([$route, 'pk' => $id])->url;
                return ['url' => \yii\helpers\Url::to($url, true), 'label' => $label . $id];
            }
        }
        return null;
    }

    // Internal state of runs, shared with the executor bridge.

    public static function find(int $chatId): array
    {
        $chat = (new Query())->from(self::CHAT)->where(self::scope() + ['id' => $chatId])->one();
        if (!$chat) {
            throw new NotFoundHttpException('Чат не найден.');
        }
        return $chat;
    }

    public static function findRun(int $runId): array
    {
        $run = (new Query())->from(self::RUN)->where(['id' => $runId])->one();
        if (!$run) {
            throw new NotFoundHttpException();
        }
        return $run;
    }

    public static function lock(int $chatId): void
    {
        Yii::$app->db->createCommand('SELECT id FROM {{%cms_ai_chat}} WHERE id=:id FOR UPDATE', [':id' => $chatId])->queryScalar();
    }

    public static function event(array $run, string $type, string $text, ?array $data = null): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->insert(self::EVENT, [
            'run_id' => $run['id'], 'chat_id' => $run['chat_id'], 'type' => $type, 'text' => mb_substr($text, 0, 1000),
            'data' => $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => time(),
        ])->execute();
        return (int)$db->lastInsertID;
    }

    /** Final state of a run with its answer in the chat; the caller holds the chat lock. */
    public static function finish(array $run, string $status, string $text, ?array $pending, array $screenshots = []): int
    {
        $messageId = self::assistant((int)$run['chat_id'], $text, $pending);
        foreach ($screenshots as $file) {
            Yii::$app->db->createCommand()->insert(self::ATTACHMENT, [
                'message_id' => $messageId, 'name' => $file['name'], 'size' => strlen($file['bytes']), 'content' => $file['bytes'],
            ])->execute();
        }
        $token = RunCredentials::decrypt($run['credential'] ?? null);
        if ($token !== null) {
            RunCredentials::revoke($token);
        }
        Yii::$app->db->createCommand()->update(self::RUN, [
            'status' => $status, 'credential' => null, 'updated_at' => time(), 'finished_at' => time(),
        ], ['id' => $run['id']])->execute();
        self::event($run, $status === 'succeeded' && $pending ? 'waiting' : $status,
            $pending ? 'Ждёт подтверждения.' : ['succeeded' => 'Готово.', 'failed' => 'Ошибка.', 'canceled' => 'Остановлено.'][$status] ?? $status);
        return $messageId;
    }

    /**
     * A job that ended without the executor's answer (crash, timeout, cancellation in cms-job)
     * leaves the run active; settle it from the job state so the chat never stays busy.
     */
    public static function reconcile(int $chatId): void
    {
        $rows = (new Query())->from(['r' => self::RUN])->leftJoin(['j' => '{{%cms_job_run}}'], 'j.id = r.job_run_id')
            ->where(['r.chat_id' => $chatId, 'r.status' => self::ACTIVE])
            ->select(['r.*', 'job_status' => 'j.status', 'job_error' => 'j.error_message'])->all();
        foreach ($rows as $row) {
            $final = in_array($row['job_status'], ['succeeded', 'succeeded_with_warnings', 'failed', 'cancelled', 'timed_out'], true);
            $orphan = $row['job_run_id'] === null && (int)$row['created_at'] < time() - 120;
            if (!$final && !$orphan) {
                continue;
            }
            Yii::$app->db->transaction(function () use ($row, $chatId) {
                self::lock($chatId);
                $run = self::findRun((int)$row['id']);
                if (!in_array($run['status'], self::ACTIVE, true)) {
                    return;
                }
                $canceled = $row['job_status'] === 'cancelled' || $run['cancel_requested_at'];
                $reason = $row['job_error'] ? ' ' . mb_substr((string)$row['job_error'], 0, 300) : '';
                self::finish($run, $canceled ? 'canceled' : 'failed',
                    $canceled ? 'Поручение остановлено.' : 'Не удалось выполнить поручение.' . $reason, null);
            });
        }
    }

    private static function createRun(array $chat, int $messageId, string $requestKey, ?array $confirmation): int
    {
        $db = Yii::$app->db;
        $userId = (int)Yii::$app->user->id;
        $db->createCommand()->insert(self::RUN, [
            'chat_id' => $chat['id'], 'message_id' => $messageId, 'request_key' => $requestKey,
            'confirmation' => $confirmation === null ? null : json_encode($confirmation, JSON_THROW_ON_ERROR),
            'engine' => $chat['engine'], 'status' => 'queued', 'state' => '{}',
            'credential' => RunCredentials::encrypt(RunCredentials::issue($userId)),
            'created_by' => $userId, 'created_at' => time(), 'updated_at' => time(),
        ])->execute();
        $runId = (int)$db->lastInsertID;
        self::event(self::findRun($runId), 'queued', $confirmation ? 'Выполняю подтверждённое действие.' : 'Поручение принято.');
        return $runId;
    }

    private static function queue(int $runId): void
    {
        $run = self::findRun($runId);
        try {
            $job = Yii::$app->jobs->push(self::JOB_TYPE, ['run' => $runId], [
                'siteId' => self::scope()['site_id'], 'createdBy' => (int)$run['created_by'], 'triggerRef' => (string)$run['chat_id'],
            ]);
        } catch (\Throwable $e) {
            Yii::error($e, __METHOD__);
            Yii::$app->db->transaction(function () use ($run) {
                self::lock((int)$run['chat_id']);
                self::finish(self::findRun((int)$run['id']), 'failed', 'Не удалось поставить поручение в очередь.', null);
            });
            return;
        }
        Yii::$app->db->createCommand()->update(self::RUN, ['job_run_id' => $job->id], ['id' => $runId])->execute();
    }

    private static function assistant(int $chatId, string $text, ?array $pending): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->insert(self::MESSAGE, ['chat_id' => $chatId, 'role' => 'assistant', 'text' => $text, 'created_at' => time()])->execute();
        $id = (int)$db->lastInsertID;
        $db->createCommand()->update(self::CHAT, [
            'updated_at' => time(), 'pending' => $pending ? json_encode($pending, JSON_THROW_ON_ERROR) : null,
        ], ['id' => $chatId])->execute();
        return $id;
    }

    private static function latestRun(int $chatId): ?array
    {
        return (new Query())->from(self::RUN)->where(['chat_id' => $chatId])->orderBy(['id' => SORT_DESC])->one() ?: null;
    }

    private static function runEvents(int $runId): array
    {
        return array_map([self::class, 'castEvent'], (new Query())->from(self::EVENT)->where(['run_id' => $runId])
            ->select(['id', 'run_id', 'type', 'text', 'created_at'])->orderBy('id')->all());
    }

    private static function castEvent(array $event): array
    {
        return ['id' => (int)$event['id'], 'run' => (int)$event['run_id'], 'type' => $event['type'],
            'text' => $event['text'], 'at' => (int)$event['created_at']];
    }

    private static function runSummary(array $run): array
    {
        return ['id' => (int)$run['id'], 'status' => $run['status'], 'cancelRequested' => (bool)$run['cancel_requested_at']];
    }

    /** The executor's internal fields stay on the server; the client needs the tool and arguments to show. */
    private static function publicPending(?array $pending): ?array
    {
        return $pending ? ['id' => $pending['id'], 'tool' => $pending['tool'] ?? '', 'args' => $pending['args'] ?? []] : null;
    }
}
