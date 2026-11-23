<?php

use yii\db\Migration;

class m261121_220000_ai_chat_sidebar extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_ai_chat}}', 'is_pinned', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%cms_ai_chat}}', 'project_id', $this->integer());
        $this->addForeignKey('ai_chat_project', '{{%cms_ai_chat}}', 'project_id', '{{%cms_project}}', 'id', 'SET NULL');
    }

    public function safeDown()
    {
        $this->dropForeignKey('ai_chat_project', '{{%cms_ai_chat}}');
        $this->dropColumn('{{%cms_ai_chat}}', 'project_id');
        $this->dropColumn('{{%cms_ai_chat}}', 'is_pinned');
    }
}
