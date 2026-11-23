<?php

use yii\db\Migration;

final class m261121_120000_create_cms_ai_task_request extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_ai_task_request}}', [
            'actor_id' => $this->integer()->notNull(),
            'request_key' => $this->char(32)->notNull(),
            'fingerprint' => $this->char(64)->notNull(),
            'task_id' => $this->integer(),
            'existing_task' => $this->boolean()->notNull()->defaultValue(false),
            'PRIMARY KEY ([[actor_id]], [[request_key]])',
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_task_request}}');
    }
}
