<?php

use yii\db\Migration;

class m261121_230000_ai_chat_engine extends Migration
{
    public function up()
    {
        $this->addColumn('{{%cms_ai_chat}}', 'engine', $this->string(64)->notNull()->defaultValue('codex'));
        // Pilot chats already have a provider session. Preserve their original profile.
        $this->execute("UPDATE {{%cms_ai_chat}} c INNER JOIN {{%cms_ai_engine_session}} s ON s.chat_id=c.id
            SET c.engine=s.engine WHERE s.engine IN ('claude-vclaude','claude-personal')");
    }

    public function down()
    {
        $this->dropColumn('{{%cms_ai_chat}}', 'engine');
    }
}
