<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\mcp\McpComponent;
use skeeks\cms\models\CmsTask;
use Yii;
use yii\db\Connection;
use yii\db\Expression;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;

final class TaskCreation
{
    private const TABLE = '{{%cms_ai_task_request}}';

    public function create(array $input): array
    {
        $actorId = (int)Yii::$app->user->id;
        if (!$actorId) {
            throw new ForbiddenHttpException();
        }

        $key = $input['request_key'] ?? null;
        $title = $input['title'] ?? null;
        $description = $input['description'] ?? '';
        $companyId = $input['company_id'] ?? null;
        $serviceId = $input['service_id'] ?? null;
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/', $key)
            || !is_string($title) || trim($title) === '' || mb_strlen($title) > 255
            || !is_string($description) || mb_strlen($description) > 3000
            || !is_int($companyId) || $companyId < 0 || !is_int($serviceId) || $serviceId < 0
            || (!$companyId && !$serviceId)) {
            throw new \InvalidArgumentException('Некорректные данные задачи.');
        }

        /** @var McpComponent $api */
        $api = Yii::$app->cmsMcp;
        $token = $api->currentAccessToken;
        if (!$token) {
            throw new ForbiddenHttpException();
        }
        $call = static function (string $tool, array $arguments) use ($api, $token): array {
            return $api->executeTool($tool, $arguments, $token, ['transport' => 'internal']);
        };

        if ($serviceId) {
            $service = $call('cms_deal_get', ['id' => $serviceId]);
            if ((int)($service['cms_company_id'] ?? 0) !== $companyId) {
                throw new \InvalidArgumentException('Услуга относится к другой компании.');
            }
        }
        if ($companyId) {
            $call('cms_company_get', ['id' => $companyId]);
        }

        $title = trim($title);
        $description = trim($description);
        $fingerprint = hash('sha256', json_encode(
            [$title, $description, $companyId, $serviceId],
            JSON_THROW_ON_ERROR
        ));
        $identity = Yii::$app->user->identity;
        $record = ['actor_id' => $actorId, 'request_key' => $key];
        $targetLock = "skeeks-ai-task-$companyId-$serviceId";
        $db = CmsTask::getDb();

        if (!(int)$db->createCommand('SELECT GET_LOCK(:name, 5)', [':name' => $targetLock])->queryScalar()) {
            throw new \RuntimeException('Компания или услуга обрабатывается. Повторите подтверждение.');
        }
        try {
            return $db->transaction(function (Connection $db) use (
                $call, $record, $identity, $fingerprint, $title, $description, $companyId, $serviceId
            ): array {
                $db->createCommand()->upsert(self::TABLE, $record + ['fingerprint' => $fingerprint], false)->execute();
                $row = $db->createCommand(
                    'SELECT [[fingerprint]], [[task_id]], [[existing_task]] FROM ' . self::TABLE
                    . ' WHERE [[actor_id]] = :actor AND [[request_key]] = :key FOR UPDATE',
                    [':actor' => $record['actor_id'], ':key' => $record['request_key']]
                )->queryOne();
                if ($row['fingerprint'] !== $fingerprint) {
                    throw new \InvalidArgumentException('У этого поручения уже были другие данные.');
                }

                $taskId = (int)$row['task_id'];
                $existing = (bool)$row['existing_task'];
                if (!$taskId) {
                    $table = CmsTask::tableName();
                    $taskId = (int)CmsTask::find()->forManager($identity)
                        ->select($table . '.id')
                        ->andWhere([
                            $table . '.cms_company_id' => $companyId ?: null,
                            $table . '.cms_deal_id' => $serviceId ?: null,
                            $table . '.status' => CmsTask::activeStatuses(),
                        ])
                        ->andWhere(['=', new Expression('LOWER(TRIM(' . $table . '.[[name]]))'), mb_strtolower($title)])
                        ->orderBy([$table . '.id' => SORT_ASC])
                        ->limit(1)->scalar();
                    $existing = $taskId > 0;
                    if (!$existing) {
                        $args = ['name' => $title, 'description' => \yii\helpers\Html::encode($description)];
                        if ($companyId) {
                            $args['cms_company_id'] = $companyId;
                        }
                        if ($serviceId) {
                            $args['cms_deal_id'] = $serviceId;
                        }
                        $created = $call('cms_task_create', $args);
                        $taskId = (int)($created['id'] ?? 0);
                        if (!$taskId) {
                            throw new \RuntimeException('CRM не вернула номер задачи.');
                        }
                    }
                    $db->createCommand()->update(self::TABLE, [
                        'task_id' => $taskId,
                        'existing_task' => $existing,
                    ], $record)->execute();
                }

                $task = $call('cms_task_get', ['id' => $taskId]);
                return [
                    'id' => $taskId,
                    'title' => $task['name'],
                    'status' => $task['status'],
                    'existing' => $existing,
                    'url' => Url::to(['/cms/admin-cms-task/view', 'pk' => $taskId], true),
                ];
            });
        } finally {
            $db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $targetLock])->queryScalar();
        }
    }
}
