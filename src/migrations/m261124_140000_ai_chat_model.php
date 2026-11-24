<?php

use yii\db\Migration;

/** The model chosen in a chat for its next runs; null means the engine default. */
class m261124_140000_ai_chat_model extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_ai_chat}}', 'model', $this->string(100));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_ai_chat}}', 'model');
    }
}
