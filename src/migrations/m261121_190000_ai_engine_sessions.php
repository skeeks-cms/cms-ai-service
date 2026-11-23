<?php

use yii\db\Migration;
use yii\db\Query;

class m261121_190000_ai_engine_sessions extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_ai_engine_session}}';
        $this->createTable($table, [
            'chat_id' => $this->integer()->notNull(),
            'engine' => 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'session_id' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
            'PRIMARY KEY (chat_id, engine)',
        ]);
        $this->createIndex('ai_engine_session_unique', $table, ['engine', 'session_id'], true);
        $this->addForeignKey('ai_engine_session_chat', $table, 'chat_id', '{{%cms_ai_chat}}', 'id', 'CASCADE');
        $this->insert($table, (new Query())->select(['chat_id' => 'id', 'engine' => new \yii\db\Expression("'codex'"), 'session_id' => 'thread_id'])
            ->from('{{%cms_ai_chat}}')->where(['not', ['thread_id' => null]]));
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_engine_session}}');
    }
}
