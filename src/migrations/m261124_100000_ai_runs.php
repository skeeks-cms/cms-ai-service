<?php

use yii\db\Migration;

/**
 * Runs of chat messages and their persisted event stream (API v1).
 * A run is one cms-job execution of a user message or of a confirmed action.
 */
class m261124_100000_ai_runs extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_ai_run}}', [
            'id' => $this->primaryKey(),
            'chat_id' => $this->integer()->notNull(),
            'message_id' => $this->integer()->notNull(),
            'request_key' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'confirmation' => $this->text(),
            'job_run_id' => $this->integer(),
            'engine' => 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'status' => $this->string(24)->notNull(),
            'state' => $this->text()->notNull(),
            'credential' => $this->binary(),
            'error' => $this->string(500),
            'cancel_requested_at' => $this->integer(),
            'created_by' => $this->integer()->notNull(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
            'finished_at' => $this->integer(),
        ]);
        $this->createIndex('ai_run_request', '{{%cms_ai_run}}', ['chat_id', 'request_key'], true);
        $this->createIndex('ai_run_job', '{{%cms_ai_run}}', 'job_run_id', true);
        $this->createIndex('ai_run_chat_status', '{{%cms_ai_run}}', ['chat_id', 'status']);
        $this->addForeignKey('ai_run_chat', '{{%cms_ai_run}}', 'chat_id', '{{%cms_ai_chat}}', 'id', 'CASCADE');

        $this->createTable('{{%cms_ai_run_event}}', [
            'id' => $this->bigPrimaryKey(),
            'run_id' => $this->integer()->notNull(),
            'chat_id' => $this->integer()->notNull(),
            'type' => 'VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'text' => $this->string(1000)->notNull()->defaultValue(''),
            'data' => $this->text(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('ai_run_event_chat', '{{%cms_ai_run_event}}', ['chat_id', 'id']);
        $this->createIndex('ai_run_event_run', '{{%cms_ai_run_event}}', ['run_id', 'id']);
        $this->addForeignKey('ai_run_event_run', '{{%cms_ai_run_event}}', 'run_id', '{{%cms_ai_run}}', 'id', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_run_event}}');
        $this->dropTable('{{%cms_ai_run}}');
    }
}
