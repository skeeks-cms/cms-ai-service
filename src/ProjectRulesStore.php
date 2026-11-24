<?php

namespace skeeks\cms\ai\service;

use Yii;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;

/**
 * Rules and skills in the central service. SkeekS rules and shared skills are files of this
 * package (knowledge/); the rules and skills of a project are stored by project with every
 * edition kept. A save with a stale revision is a conflict. Rights are checked by the caller.
 */
final class ProjectRulesStore
{
    public const MAX_LENGTH = 12000;

    /** Current edition of the project rules and skills. */
    public static function get(int $site): array
    {
        $row = (new Query())->from('{{%cms_ai_project}}')->where(['site_id' => $site])
            ->select(['instructions', 'skills', 'revision', 'updated_at'])->one();
        return $row ? ['instructions' => (string)$row['instructions'], 'skills' => (string)$row['skills'],
            'revision' => (int)$row['revision'], 'updated_at' => (int)$row['updated_at']]
            : ['instructions' => '', 'skills' => '', 'revision' => 0, 'updated_at' => 0];
    }

    /** Saves a new edition over $revision; returns the current edition. */
    public static function save(int $site, ?int $user, int $revision, $instructions, $skills): array
    {
        $values = [];
        foreach (['instructions' => $instructions, 'skills' => $skills] as $field => $value) {
            if (!is_string($value) || mb_strlen($value) > self::MAX_LENGTH) {
                throw new BadRequestHttpException('Превышен размер настроек.');
            }
            $values[$field] = trim($value);
        }
        if ($values['skills'] !== '' && !preg_match('/^## [^\r\n]+\R.+/su', $values['skills'])) {
            throw new BadRequestHttpException('Начните навык строкой «## Название», ниже запишите инструкции.');
        }
        $db = Yii::$app->db;
        $db->transaction(static function () use ($db, $site, $user, $revision, $values) {
            $table = '{{%cms_ai_project}}';
            $db->createCommand()->upsert($table, ['site_id' => $site, 'instructions' => '', 'skills' => '', 'revision' => 0], false)->execute();
            $now = time();
            if (!$db->createCommand()->update($table, $values + ['revision' => $revision + 1, 'updated_by' => $user, 'updated_at' => $now],
                ['site_id' => $site, 'revision' => $revision])->execute()) {
                throw new ConflictHttpException('Настройки изменились. Откройте их заново.');
            }
            $db->createCommand()->insert('{{%cms_ai_project_rules_edition}}', $values + [
                'site_id' => $site, 'revision' => $revision + 1, 'user_id' => $user, 'created_at' => $now,
            ])->execute();
        });
        return self::get($site);
    }

    /** Recent editions, newest first: revision, author and time (without the texts). */
    public static function editions(int $site, int $limit = 20): array
    {
        return array_map(static fn(array $r) => ['revision' => (int)$r['revision'], 'user_id' => $r['user_id'] === null ? null : (int)$r['user_id'],
            'created_at' => (int)$r['created_at']],
            (new Query())->from('{{%cms_ai_project_rules_edition}}')->where(['site_id' => $site])
                ->select(['revision', 'user_id', 'created_at'])->orderBy(['revision' => SORT_DESC])->limit($limit)->all());
    }

    /** SkeekS rules and the names of the shared skills (files of this package). */
    public static function shared(): array
    {
        $root = dirname(__DIR__) . '/knowledge';
        $skills = [];
        foreach (glob($root . '/skills/*/SKILL.md') ?: [] as $file) {
            $skills[] = 'skeeks/' . basename(dirname($file));
        }
        return ['rules' => is_file($root . '/AGENTS.md') ? (string)file_get_contents($root . '/AGENTS.md') : '', 'skills' => $skills];
    }
}
