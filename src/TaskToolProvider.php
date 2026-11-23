<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;

final class TaskToolProvider implements McpToolProviderInterface
{
    public function getTools(): array
    {
        $profile = new CallbackTool([
            'name' => 'cms_ai_current_user_get',
            'description' => 'Имя и ID текущего пользователя CRM по OAuth.',
            'requiredScope' => 'cms.user.read',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            'callback' => static function (): array {
                $user = \Yii::$app->user->identity;
                return ['id' => (int)$user->id, 'name' => (string)$user->displayName];
            },
        ]);
        return [...\skeeks\cms\ai\ProjectFiles::tools(), $profile, \skeeks\cms\ai\DraftJob::tool(), new CallbackTool([
            'name' => 'cms_ai_task_create',
            'description' => 'Создаёт одну задачу по подтверждённому поручению SkeekS AI. Повтор ключа с теми же данными возвращает ту же задачу.',
            'requiredScope' => 'cms.task.write',
            'callback' => [new TaskCreation(), 'create'],
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['request_key', 'title', 'company_id', 'service_id'],
                'properties' => [
                    'request_key' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'company_id' => ['type' => 'integer', 'minimum' => 0],
                    'service_id' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
        ])];
    }
}
