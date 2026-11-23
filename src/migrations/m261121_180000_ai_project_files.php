<?php
use yii\db\Migration;

class m261121_180000_ai_project_files extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_ai_project}}', [
            'site_id' => 'int NOT NULL PRIMARY KEY',
            'instructions' => $this->text()->notNull(),
            'skills' => $this->text()->notNull(),
            'revision' => $this->integer()->notNull(),
        ]);
        $this->createTable('{{%cms_ai_attachment}}', [
            'id' => $this->primaryKey(),
            'message_id' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'size' => $this->integer()->notNull(),
            'content' => 'MEDIUMBLOB NOT NULL',
        ]);
        $this->addForeignKey('ai_attachment_message','{{%cms_ai_attachment}}','message_id','{{%cms_ai_chat_message}}','id','CASCADE');
    }
    public function safeDown()
    {
        $this->dropTable('{{%cms_ai_attachment}}');
        $this->dropTable('{{%cms_ai_project}}');
    }
}
