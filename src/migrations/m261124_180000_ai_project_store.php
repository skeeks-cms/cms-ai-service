<?php

use yii\db\Migration;

/**
 * Shared project files in the central service: current files and folders of a project, immutable
 * contents by revision, and the log of changes. The pilot folder in the site runtime is imported
 * with its paths and revisions; the folder itself is left in place.
 */
class m261124_180000_ai_project_store extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;
        $this->createTable('{{%cms_ai_project_blob}}', [
            'revision' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY',
            'size' => $this->integer()->notNull(),
            'content' => 'MEDIUMBLOB NOT NULL',
            'created_at' => $this->integer()->notNull(),
        ], $options);
        $this->createTable('{{%cms_ai_project_file}}', [
            'id' => $this->primaryKey(),
            'site_id' => $this->integer()->notNull(),
            'path' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
            'is_dir' => $this->boolean()->notNull()->defaultValue(false),
            'revision' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'size' => $this->integer()->notNull()->defaultValue(0),
            'updated_by' => $this->integer(),
            'updated_at' => $this->integer()->notNull(),
        ], $options);
        $this->createIndex('ai_project_file_path', '{{%cms_ai_project_file}}', ['site_id', 'path'], true);
        $this->addForeignKey('ai_project_file_blob', '{{%cms_ai_project_file}}', 'revision', '{{%cms_ai_project_blob}}', 'revision', 'RESTRICT');
        $this->createTable('{{%cms_ai_project_file_log}}', [
            'id' => $this->primaryKey(),
            'site_id' => $this->integer()->notNull(),
            'path' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
            'action' => $this->string(16)->notNull(),
            'revision' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'target' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            'user_id' => $this->integer(),
            'created_at' => $this->integer()->notNull(),
        ], $options);
        $this->createIndex('ai_project_file_log_path', '{{%cms_ai_project_file_log}}', ['site_id', 'path', 'id']);
        $this->importPilotFolders();
    }

    private function importPilotFolders(): void
    {
        $base = Yii::getAlias('@root/console/runtime/cms-ai/projects', false);
        if (!$base || !is_dir($base)) {
            return;
        }
        $now = time();
        foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $site = basename($dir);
            if (!ctype_digit($site) || !is_file($dir.'/index.json')) {
                continue;
            }
            $index = json_decode((string)file_get_contents($dir.'/index.json'), true);
            foreach (is_array($index) ? $index : [] as $path => $meta) {
                $revision = (string)($meta['revision'] ?? '');
                $file = $dir.'/blobs/'.$revision;
                if (!preg_match('/^[a-f0-9]{64}$/', $revision) || !is_file($file)) {
                    echo "    > skipped $site/$path: no content\n";
                    continue;
                }
                $data = (string)file_get_contents($file);
                if (hash('sha256', $data) !== $revision) {
                    echo "    > skipped $site/$path: content does not match its revision\n";
                    continue;
                }
                $this->db->createCommand()->upsert('{{%cms_ai_project_blob}}',
                    ['revision' => $revision, 'size' => strlen($data), 'content' => $data, 'created_at' => $now], false)->execute();
                $this->db->createCommand()->upsert('{{%cms_ai_project_file}}',
                    ['site_id' => (int)$site, 'path' => (string)$path, 'is_dir' => 0, 'revision' => $revision, 'size' => strlen($data), 'updated_at' => $now], false)->execute();
                $this->insert('{{%cms_ai_project_file_log}}',
                    ['site_id' => (int)$site, 'path' => (string)$path, 'action' => 'import', 'revision' => $revision, 'created_at' => $now]);
            }
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_project_file_log}}');
        $this->dropTable('{{%cms_ai_project_file}}');
        $this->dropTable('{{%cms_ai_project_blob}}');
    }
}
