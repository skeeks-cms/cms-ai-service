<?php
use yii\db\Migration;

class m261121_200000_ai_draft_job extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_ai_draft_job}}', [
            'request_key' => $this->string(64)->notNull(),
            'chat_id' => $this->integer()->notNull(),
            'run_id' => $this->integer()->unique(),
            'credential' => $this->binary(),
            'results' => $this->text()->notNull(),
            'done' => $this->boolean()->notNull()->defaultValue(false),
            'PRIMARY KEY (chat_id, request_key)',
        ]);
        $this->addForeignKey('ai_draft_chat', '{{%cms_ai_draft_job}}', 'chat_id', '{{%cms_ai_chat}}', 'id', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_draft_job}}');
    }
}
