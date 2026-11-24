<?php

namespace skeeks\cms\ai\service;

use Yii;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

/**
 * Shared files of a project in the central service. Contents are immutable by revision (sha256);
 * a change of several files is applied in one transaction under a project lock, so other chats
 * never see half of it. Every change is logged. Rights are checked by the caller.
 */
final class ProjectFileStore
{
    public const MAX_SIZE = 4194304;

    public static function path($value, bool $folder = false): string
    {
        if (is_string($value) && $folder) {
            $value = trim($value, '/');
        }
        if (!is_string($value) || strlen($value) > 255 || !preg_match('~^[\pL\pN_-][\pL\pN_. -]*(?:/[\pL\pN_-][\pL\pN_. -]*)*$~uD', $value)) {
            throw new BadRequestHttpException('Некорректный путь файла проекта.');
        }
        return $value;
    }

    /** All files and folders of the project, sorted by path. */
    public static function all(int $site): array
    {
        return array_map(static fn(array $row) => [
            'path' => $row['path'], 'dir' => (bool)$row['is_dir'], 'revision' => $row['revision'],
            'size' => (int)$row['size'], 'updated_at' => (int)$row['updated_at'],
        ], (new Query())->from('{{%cms_ai_project_file}}')->where(['site_id' => $site])
            ->select(['path', 'is_dir', 'revision', 'size', 'updated_at'])->orderBy(['path' => SORT_ASC])->all());
    }

    /** Files only, a page at a time (the agent tool). */
    public static function files(int $site, int $offset, int $limit = 10): array
    {
        $query = (new Query())->from('{{%cms_ai_project_file}}')->where(['site_id' => $site, 'is_dir' => 0]);
        $total = (int)(clone $query)->count();
        $items = [];
        foreach ($query->select(['path', 'revision', 'size'])->orderBy(['path' => SORT_ASC])->offset($offset)->limit($limit)->all() as $row) {
            $items[] = ['path' => $row['path'], 'revision' => $row['revision'], 'size' => (int)$row['size']];
        }
        return ['files' => $items, 'total' => $total, 'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null];
    }

    /** [content, meta] of a file. */
    public static function get(int $site, string $path): array
    {
        $row = (new Query())->from(['f' => '{{%cms_ai_project_file}}'])->innerJoin(['b' => '{{%cms_ai_project_blob}}'], 'b.revision=f.revision')
            ->where(['f.site_id' => $site, 'f.path' => self::path($path), 'f.is_dir' => 0])
            ->select(['b.content', 'f.revision', 'f.size', 'f.updated_at'])->one();
        if (!$row) {
            throw new NotFoundHttpException('Файл проекта не найден.');
        }
        $content = is_resource($row['content']) ? stream_get_contents($row['content']) : (string)$row['content'];
        return [$content, ['revision' => $row['revision'], 'size' => (int)$row['size'], 'updated_at' => (int)$row['updated_at']]];
    }

    /** Content of an attachment of the user's own chat on this site. */
    public static function attachment(int $site, int $user, int $chat, int $attachment): string
    {
        $data = (new Query())->from(['a' => '{{%cms_ai_attachment}}'])
            ->innerJoin(['m' => '{{%cms_ai_chat_message}}'], 'm.id=a.message_id')
            ->innerJoin(['c' => '{{%cms_ai_chat}}'], 'c.id=m.chat_id')
            ->where(['a.id' => $attachment, 'c.id' => $chat, 'c.site_id' => $site, 'c.owner_id' => $user])
            ->select('a.content')->scalar();
        if ($data === false || $data === null) {
            throw new NotFoundHttpException('Вложение не найдено в вашем чате.');
        }
        return is_resource($data) ? stream_get_contents($data) : (string)$data;
    }

    /**
     * Saves files: [['path'=>…, 'revision'=>current or '' for a new file, 'data'=>bytes], …].
     * A file changed since the given revision is a conflict.
     */
    public static function save(int $site, ?int $user, array $files): array
    {
        if (!$files || count($files) > 16) {
            throw new BadRequestHttpException('Укажите от 1 до 16 файлов.');
        }
        return self::locked($site, static function (array $entries) use ($site, $user, $files) {
            $changed = [];
            foreach ($files as $file) {
                $path = self::path($file['path'] ?? null);
                if (isset($changed[$path])) {
                    throw new BadRequestHttpException('Путь повторяется.');
                }
                $data = $file['data'] ?? null;
                if (!is_string($data) || strlen($data) > self::MAX_SIZE) {
                    throw new BadRequestHttpException('Файл больше 4 МБ или пуст: '.$path);
                }
                $revision = hash('sha256', $data);
                $current = $entries[$path] ?? null;
                if ($current && $current['is_dir']) {
                    throw new ConflictHttpException('По этому пути уже есть папка: '.$path);
                }
                $was = $current['revision'] ?? '';
                if ($was !== $revision && $was !== (string)($file['revision'] ?? '')) {
                    throw new ConflictHttpException('Версия файла изменилась: '.$path);
                }
                self::checkParents($entries, $path);
                if ($was !== $revision) {
                    self::blob($revision, $data);
                    self::put($site, $path, false, $revision, strlen($data), $user);
                    $entries[$path] = ['is_dir' => false, 'revision' => $revision];
                    self::log($site, $path, $was === '' ? 'create' : 'update', $revision, null, $user);
                }
                $changed[$path] = $revision;
            }
            return ['saved' => array_keys($changed), 'revisions' => $changed];
        });
    }

    /** Creates an empty folder (and its missing parents). */
    public static function folder(int $site, ?int $user, string $path): array
    {
        $path = self::path($path, true);
        return self::locked($site, static function (array $entries) use ($site, $user, $path) {
            $parts = explode('/', $path);
            for ($i = 1; $i <= count($parts); $i++) {
                $part = implode('/', array_slice($parts, 0, $i));
                if (isset($entries[$part])) {
                    if (!$entries[$part]['is_dir']) {
                        throw new ConflictHttpException('По этому пути уже есть файл: '.$part);
                    }
                    continue;
                }
                if ($i < count($parts) && self::hasChildren($entries, $part)) {
                    continue;
                }
                self::put($site, $part, true, null, 0, $user);
                $entries[$part] = ['is_dir' => true, 'revision' => null];
                self::log($site, $part, 'folder', null, null, $user);
            }
            return ['folder' => $path];
        });
    }

    /** Deletes files and folders (with their contents); a file changed since its given revision is a conflict. */
    public static function delete(int $site, ?int $user, array $items): array
    {
        if (!$items || count($items) > 100) {
            throw new BadRequestHttpException('Укажите от 1 до 100 путей.');
        }
        return self::locked($site, static function (array $entries) use ($site, $user, $items) {
            $deleted = [];
            foreach ($items as $item) {
                $path = self::path($item['path'] ?? null, true);
                $entry = $entries[$path] ?? null;
                $inside = array_filter(array_keys($entries), static fn($p) => str_starts_with((string)$p, $path.'/'));
                if (!$entry && !$inside) {
                    throw new NotFoundHttpException('Не найдено: '.$path);
                }
                if ($entry && !$entry['is_dir'] && isset($item['revision']) && $item['revision'] !== $entry['revision']) {
                    throw new ConflictHttpException('Версия файла изменилась: '.$path);
                }
                foreach ([...($entry ? [$path] : []), ...$inside] as $p) {
                    Yii::$app->db->createCommand()->delete('{{%cms_ai_project_file}}', ['site_id' => $site, 'path' => $p])->execute();
                    if (!$entries[$p]['is_dir']) {
                        self::log($site, $p, 'delete', $entries[$p]['revision'], null, $user);
                    }
                    unset($entries[$p]);
                    $deleted[] = $p;
                }
                self::keepParent($site, $entries, $path, $user);
            }
            return ['deleted' => $deleted];
        });
    }

    /** Renames or moves a file or a folder with its contents; an existing target is a conflict. */
    public static function move(int $site, ?int $user, string $from, string $to): array
    {
        $from = self::path($from, true);
        $to = self::path($to, true);
        if ($from === $to || str_starts_with($to, $from.'/')) {
            throw new BadRequestHttpException('Нельзя переместить папку внутрь неё самой.');
        }
        return self::locked($site, static function (array $entries) use ($site, $user, $from, $to) {
            $moving = array_values(array_filter(array_keys($entries), static fn($p) => $p === $from || str_starts_with((string)$p, $from.'/')));
            if (!$moving) {
                throw new NotFoundHttpException('Не найдено: '.$from);
            }
            $rest = array_diff_key($entries, array_flip($moving));
            foreach ($moving as $p) {
                $target = $to.substr((string)$p, strlen($from));
                self::path($target);
                if (isset($rest[$target])) {
                    throw new ConflictHttpException('По новому пути уже есть файл или папка: '.$target);
                }
                self::checkParents($rest, $target);
                $rest[$target] = $entries[$p];
            }
            foreach ($moving as $p) {
                $target = $to.substr((string)$p, strlen($from));
                Yii::$app->db->createCommand()->update('{{%cms_ai_project_file}}', ['path' => $target, 'updated_by' => $user, 'updated_at' => time()],
                    ['site_id' => $site, 'path' => $p])->execute();
                self::dropParentMarkers($site, $target);
                if (!$entries[$p]['is_dir']) {
                    self::log($site, (string)$p, 'move', $entries[$p]['revision'], $target, $user);
                }
            }
            foreach ($moving as $p) {
                unset($entries[$p]);
            }
            self::keepParent($site, $entries, $from, $user);
            return ['moved' => $from, 'to' => $to];
        });
    }

    /** Runs a change under the project lock in one transaction; the callback gets the current entries by path. */
    private static function locked(int $site, callable $change): array
    {
        $db = Yii::$app->db;
        $name = 'cms_ai_project_files_'.$site;
        if (!(int)$db->createCommand('SELECT GET_LOCK(:name, 10)', [':name' => $name])->queryScalar()) {
            throw new ConflictHttpException('Файлы проекта сейчас меняются, повторите через несколько секунд.');
        }
        try {
            return $db->transaction(static function () use ($site, $change) {
                $entries = [];
                foreach ((new Query())->from('{{%cms_ai_project_file}}')->where(['site_id' => $site])->select(['path', 'is_dir', 'revision'])->all() as $row) {
                    $entries[$row['path']] = ['is_dir' => (bool)$row['is_dir'], 'revision' => $row['revision']];
                }
                return $change($entries);
            });
        } finally {
            $db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $name])->execute();
        }
    }

    /** A file may not sit under a file, and a path may not be a folder that has contents. */
    private static function checkParents(array $entries, string $path): void
    {
        $parts = explode('/', $path);
        for ($i = 1; $i < count($parts); $i++) {
            $parent = implode('/', array_slice($parts, 0, $i));
            if (isset($entries[$parent]) && !$entries[$parent]['is_dir']) {
                throw new ConflictHttpException('Путь файла занят файлом: '.$parent);
            }
        }
        if (self::hasChildren($entries, $path)) {
            throw new ConflictHttpException('По этому пути уже есть папка: '.$path);
        }
    }

    /** The folder a path was taken from stays visible when it becomes empty. */
    private static function keepParent(int $site, array &$entries, string $path, ?int $user): void
    {
        $parent = dirname($path);
        if ($parent === '.' || isset($entries[$parent]) || self::hasChildren($entries, $parent)) {
            return;
        }
        Yii::$app->db->createCommand()->insert('{{%cms_ai_project_file}}', [
            'site_id' => $site, 'path' => $parent, 'is_dir' => 1, 'revision' => null, 'size' => 0, 'updated_by' => $user, 'updated_at' => time(),
        ])->execute();
        $entries[$parent] = ['is_dir' => true, 'revision' => null];
    }

    private static function hasChildren(array $entries, string $path): bool
    {
        foreach (array_keys($entries) as $p) {
            if (str_starts_with((string)$p, $path.'/')) {
                return true;
            }
        }
        return false;
    }

    private static function blob(string $revision, string $data): void
    {
        $exists = (new Query())->from('{{%cms_ai_project_blob}}')->where(['revision' => $revision])->exists();
        if (!$exists) {
            Yii::$app->db->createCommand()->insert('{{%cms_ai_project_blob}}',
                ['revision' => $revision, 'size' => strlen($data), 'content' => $data, 'created_at' => time()])->execute();
        }
    }

    private static function put(int $site, string $path, bool $dir, ?string $revision, int $size, ?int $user): void
    {
        $db = Yii::$app->db;
        $values = ['is_dir' => (int)$dir, 'revision' => $revision, 'size' => $size, 'updated_by' => $user, 'updated_at' => time()];
        $updated = $db->createCommand()->update('{{%cms_ai_project_file}}', $values, ['site_id' => $site, 'path' => $path])->execute();
        $exists = $updated || (new Query())->from('{{%cms_ai_project_file}}')->where(['site_id' => $site, 'path' => $path])->exists();
        if (!$exists) {
            $db->createCommand()->insert('{{%cms_ai_project_file}}', ['site_id' => $site, 'path' => $path] + $values)->execute();
        }
        self::dropParentMarkers($site, $path);
    }

    /** A file or a folder inside an empty folder makes the folder marker unnecessary. */
    private static function dropParentMarkers(int $site, string $path): void
    {
        $parts = explode('/', $path);
        array_pop($parts);
        while ($parts) {
            Yii::$app->db->createCommand()->delete('{{%cms_ai_project_file}}', ['site_id' => $site, 'path' => implode('/', $parts), 'is_dir' => 1])->execute();
            array_pop($parts);
        }
    }

    private static function log(int $site, string $path, string $action, ?string $revision, ?string $target, ?int $user): void
    {
        Yii::$app->db->createCommand()->insert('{{%cms_ai_project_file_log}}', [
            'site_id' => $site, 'path' => $path, 'action' => $action, 'revision' => $revision,
            'target' => $target, 'user_id' => $user, 'created_at' => time(),
        ])->execute();
    }
}
