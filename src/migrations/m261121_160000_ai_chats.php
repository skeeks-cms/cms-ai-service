<?php

use yii\db\Migration;

class m261121_160000_ai_chats extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_ai_chat}}', [
            'id' => $this->primaryKey(), 'owner_id' => $this->integer()->notNull(),
            'site_id' => $this->integer()->notNull(), 'company_id' => $this->integer(),
            'title' => $this->string(120)->notNull(), 'thread_id' => $this->string(64),
            'pending' => $this->text(), 'updated_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('ai_chat_owner', '{{%cms_ai_chat}}', ['site_id','owner_id','updated_at']);
        $this->createIndex('ai_chat_thread', '{{%cms_ai_chat}}', 'thread_id', true);
        $this->createTable('{{%cms_ai_chat_message}}', [
            'id' => $this->primaryKey(), 'chat_id' => $this->integer()->notNull(),
            'role' => $this->string(16)->notNull(), 'text' => $this->text()->notNull(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->addForeignKey('ai_message_chat', '{{%cms_ai_chat_message}}', 'chat_id', '{{%cms_ai_chat}}', 'id', 'CASCADE');
    }
    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_chat_message}}');
        $this->dropTable('{{%cms_ai_chat}}');
    }
}
